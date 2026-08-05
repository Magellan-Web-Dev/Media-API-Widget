<?php
/**
 * In-memory doubles for the WordPress post, meta, term, and cron APIs.
 *
 * The media post index writes posts, post meta, and terms. None of that can go
 * through the $wpdb double in bootstrap.php: its store is a flat
 * option_name => string map, its query() understands only five option-shaped
 * statements, and its insert() ignores the table name and appends to the API
 * call log — so a stray $wpdb->insert() for a post would silently corrupt the
 * daily-limit group's assertions. A separate array-backed store keeps the two
 * worlds apart, and the production code is written to use only the WordPress
 * post/meta/term API so it never reaches for $wpdb at all.
 *
 * These doubles are narrow on purpose. Several are deliberately *not* the
 * simplest thing that would pass:
 *
 * - wp_insert_post() does not merge over an existing row, exactly as core does
 *   not. That is what lets a test prove the transcript-preservation rule: code
 *   that updated via wp_insert_post() would blank post_content here, just as it
 *   would on a real site.
 * - wp_update_post() records the raw arguments before merging, so a test can
 *   assert the code never even *offered* to overwrite a field.
 * - wp_schedule_single_event() reproduces core's ten-minute duplicate window,
 *   so a test cannot "prove" a deduplication guarantee core does not give.
 * - wp_kses_post() actually strips something, so asserting that it ran is
 *   meaningful rather than vacuous.
 *
 * Loaded once from bootstrap.php. Like the rest of that file, nothing here is
 * function_exists() guarded, so each function must be declared exactly once.
 */

declare(strict_types=1);

/**
 * Post, meta, term, and cron state for one test.
 *
 * Kept separate from MawTestState, which owns the options, HTTP, and hook
 * doubles, so wiring this in costs bootstrap.php a single reset call.
 */
final class MawTestPosts
{
    /** @var array<int,array<string,mixed>> Post ID => row. */
    public static array $posts = [];

    /** @var array<int,array<string,array<int,mixed>>> Post ID => meta key => values. */
    public static array $meta = [];

    /** @var array<int,array<string,array<int,int>>> Post ID => taxonomy => term IDs. */
    public static array $objectTerms = [];

    /** @var array<string,array<string,array<string,mixed>>> Taxonomy => slug => term row. */
    public static array $terms = [];

    /** @var array<string,array<string,mixed>> Post type name => registered args. */
    public static array $postTypes = [];

    /** @var array<string,array{object_types:array<int,string>,args:array<string,mixed>}> */
    public static array $taxonomies = [];

    /** @var array<string,array<string,array<string,mixed>>> Post type => meta key => args. */
    public static array $registeredMeta = [];

    /** @var array<int,array<string,array<string,array<string,mixed>>>> Timestamp => hook => args key => event. */
    public static array $cron = [];

    /** @var array<int,array<string,mixed>> Raw arguments handed to wp_insert_post(), in order. */
    public static array $insertLog = [];

    /** @var array<int,array<string,mixed>> Raw arguments handed to wp_update_post(), pre-merge, in order. */
    public static array $updateLog = [];

    /** @var int Next post ID to hand out. */
    public static int $nextPostId = 1;

    /** @var int Next term ID to hand out. */
    public static int $nextTermId = 1;

    /** @var bool When true, wp_insert_post() fails so the failed counter can be exercised. */
    public static bool $failInserts = false;

    /** @var bool When true, wp_update_post() fails. */
    public static bool $failUpdates = false;

    /** @var bool Value returned by current_user_can('manage_options'). */
    public static bool $canManageOptions = true;

    /** @var bool Value returned by is_admin(). */
    public static bool $isAdmin = false;

    /** @var bool Value returned by wp_doing_cron(). */
    public static bool $doingCron = false;

    /** @var int Author assigned to newly created posts. */
    public static int $currentUserId = 1;

    /**
     * Clears every property back to a fresh-install state.
     *
     * Deliberately does not re-register the post type or taxonomies: "the index
     * content model is not registered yet" is itself a state worth testing, so
     * each test that needs them registers them explicitly.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$posts          = [];
        self::$meta           = [];
        self::$objectTerms    = [];
        self::$terms          = [];
        self::$postTypes      = [];
        self::$taxonomies     = [];
        self::$registeredMeta = [];
        self::$cron           = [];
        self::$insertLog      = [];
        self::$updateLog      = [];

        self::$nextPostId = 1;
        self::$nextTermId = 1;

        self::$failInserts      = false;
        self::$failUpdates      = false;
        self::$canManageOptions = true;
        self::$isAdmin          = false;
        self::$doingCron        = false;
        self::$currentUserId    = 1;
    }
}

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

/**
 * @param string              $postType Post type name.
 * @param array<string,mixed> $args     Registration arguments.
 * @return object The registered arguments, so assertions can read them as properties.
 */
function register_post_type(string $postType, array $args = []): object
{
    MawTestPosts::$postTypes[$postType] = $args;

    return (object) $args;
}

/**
 * @param string               $taxonomy   Taxonomy name.
 * @param string|array<int,string> $objectType Post types it applies to.
 * @param array<string,mixed>  $args       Registration arguments.
 * @return void
 */
function register_taxonomy(string $taxonomy, $objectType, array $args = []): void
{
    MawTestPosts::$taxonomies[$taxonomy] = [
        'object_types' => is_array($objectType) ? $objectType : [$objectType],
        'args'         => $args,
    ];

    if (!isset(MawTestPosts::$terms[$taxonomy])) {
        MawTestPosts::$terms[$taxonomy] = [];
    }
}

/**
 * @param string              $postType Post type the meta belongs to.
 * @param string              $metaKey  Meta key.
 * @param array<string,mixed> $args     Registration arguments.
 * @return bool Always true.
 */
function register_post_meta(string $postType, string $metaKey, array $args = []): bool
{
    MawTestPosts::$registeredMeta[$postType][$metaKey] = $args;

    return true;
}

/**
 * @param string $postType Post type name.
 * @return bool True when registered.
 */
function post_type_exists(string $postType): bool
{
    return isset(MawTestPosts::$postTypes[$postType]);
}

/**
 * @param string $taxonomy Taxonomy name.
 * @return bool True when registered.
 */
function taxonomy_exists(string $taxonomy): bool
{
    return isset(MawTestPosts::$taxonomies[$taxonomy]);
}

/**
 * @param string $postType Post type name.
 * @return object|null Registered arguments, or null.
 */
function get_post_type_object(string $postType): ?object
{
    return isset(MawTestPosts::$postTypes[$postType])
        ? (object) MawTestPosts::$postTypes[$postType]
        : null;
}

/**
 * @param string $taxonomy Taxonomy name.
 * @return object|null Registered arguments, or null.
 */
function get_taxonomy(string $taxonomy): ?object
{
    return isset(MawTestPosts::$taxonomies[$taxonomy])
        ? (object) MawTestPosts::$taxonomies[$taxonomy]['args']
        : null;
}

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

/**
 * Inserts a post, filling omitted fields with defaults.
 *
 * Critically, this does NOT merge over an existing row when an ID is supplied —
 * core does not either. post_content defaults to an empty string, so code that
 * "updates" through this function silently blanks the body. Reproducing that
 * faithfully is what makes the transcript-preservation tests real.
 *
 * @param array<string,mixed> $postarr Post fields.
 * @param bool                $wpError Whether to return a WP_Error on failure.
 * @return int|WP_Error New post ID, 0, or WP_Error.
 */
function wp_insert_post(array $postarr, bool $wpError = false)
{
    MawTestPosts::$insertLog[] = $postarr;

    if (MawTestPosts::$failInserts) {
        return $wpError ? new WP_Error('db_insert_error', 'Could not insert post into the database.') : 0;
    }

    $postarr = wp_unslash($postarr);

    $id = isset($postarr['ID']) && (int) $postarr['ID'] > 0
        ? (int) $postarr['ID']
        : MawTestPosts::$nextPostId++;

    $title = (string) ($postarr['post_title'] ?? '');

    MawTestPosts::$posts[$id] = [
        'ID'             => $id,
        'post_type'      => (string) ($postarr['post_type'] ?? 'post'),
        'post_status'    => (string) ($postarr['post_status'] ?? 'draft'),
        'post_title'     => $title,
        'post_content'   => (string) ($postarr['post_content'] ?? ''),
        'post_excerpt'   => (string) ($postarr['post_excerpt'] ?? ''),
        'post_name'      => (string) ($postarr['post_name'] ?? sanitize_title($title)),
        'post_author'    => (int) ($postarr['post_author'] ?? MawTestPosts::$currentUserId),
        'menu_order'     => (int) ($postarr['menu_order'] ?? 0),
        'post_date_gmt'  => (string) ($postarr['post_date_gmt'] ?? gmdate('Y-m-d H:i:s', maw_test_time())),
        'comment_status' => (string) ($postarr['comment_status'] ?? 'open'),
        'ping_status'    => (string) ($postarr['ping_status'] ?? 'open'),
    ];

    return $id;
}

/**
 * Updates a post, merging the supplied fields over the stored row.
 *
 * The merge is the whole point: a field omitted from $postarr keeps its stored
 * value, which is what allows an update to preserve a transcript it was not
 * given. The raw arguments are recorded before merging so a test can assert a
 * key was never even present.
 *
 * @param array<string,mixed> $postarr Post fields, including ID.
 * @param bool                $wpError Whether to return a WP_Error on failure.
 * @return int|WP_Error Post ID, 0, or WP_Error.
 */
function wp_update_post(array $postarr, bool $wpError = false)
{
    MawTestPosts::$updateLog[] = $postarr;

    $id = (int) ($postarr['ID'] ?? 0);

    if ($id === 0 || !isset(MawTestPosts::$posts[$id])) {
        return $wpError ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
    }

    if (MawTestPosts::$failUpdates) {
        return $wpError ? new WP_Error('db_update_error', 'Could not update post in the database.') : 0;
    }

    MawTestPosts::$posts[$id] = array_merge(MawTestPosts::$posts[$id], wp_unslash($postarr));

    return $id;
}

/**
 * @param int|null $post   Post ID.
 * @param string   $output Ignored; always returns an object.
 * @return object|null The post row, or null.
 */
function get_post($post = null, string $output = 'OBJECT')
{
    $id = (int) $post;

    return isset(MawTestPosts::$posts[$id]) ? (object) MawTestPosts::$posts[$id] : null;
}

/**
 * @param int|object $post Post ID or row.
 * @return string The post type, or an empty string.
 */
function get_post_type($post): string
{
    $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

    return (string) (MawTestPosts::$posts[$id]['post_type'] ?? '');
}

/**
 * Queries indexed posts.
 *
 * Supports only the arguments the production code and these tests actually use,
 * and throws on anything else. That is deliberate: a future production change
 * adding, say, a nested meta_query would fail loudly here rather than silently
 * matching everything and making a duplicate-detection test pass for the wrong
 * reason.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return array<int,mixed> Post IDs or post objects.
 * @throws RuntimeException When an unsupported argument is supplied.
 */
function get_posts(array $args = []): array
{
    $supported = [
        'post_type', 'post_status', 'posts_per_page', 'numberposts', 'offset',
        'fields', 'orderby', 'order', 'post__in', 'meta_key', 'meta_value',
        'meta_query', 'tax_query',
    ];

    // Accepted and ignored: performance and cache flags with no effect here.
    $ignored = [
        'no_found_rows', 'suppress_filters', 'update_post_meta_cache',
        'update_post_term_cache', 'ignore_sticky_posts', 'cache_results',
        'lazy_load_term_meta', 'paged',
    ];

    foreach (array_keys($args) as $key) {
        if (!in_array($key, $supported, true) && !in_array($key, $ignored, true)) {
            throw new RuntimeException("get_posts() double: unsupported query arg '" . $key . "'");
        }
    }

    $postTypes = $args['post_type'] ?? 'post';
    $postTypes = is_array($postTypes) ? $postTypes : [$postTypes];

    $statuses = $args['post_status'] ?? 'publish';
    $statuses = is_array($statuses) ? $statuses : [$statuses];
    $anyStatus = in_array('any', $statuses, true);

    $matches = [];

    foreach (MawTestPosts::$posts as $id => $row) {
        if (!in_array($row['post_type'], $postTypes, true)) {
            continue;
        }

        if ($anyStatus) {
            if (in_array($row['post_status'], ['trash', 'auto-draft'], true)) {
                continue;
            }
        } elseif (!in_array($row['post_status'], $statuses, true)) {
            continue;
        }

        if (isset($args['post__in']) && !in_array($id, array_map('intval', (array) $args['post__in']), true)) {
            continue;
        }

        if (!maw_test_posts_match_meta($id, $args)) {
            continue;
        }

        if (!maw_test_posts_match_tax($id, $args)) {
            continue;
        }

        $matches[] = $id;
    }

    $matches = maw_test_posts_sort($matches, $args);

    $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
    if ($offset > 0) {
        $matches = array_slice($matches, $offset);
    }

    $limit = (int) ($args['posts_per_page'] ?? $args['numberposts'] ?? -1);
    if ($limit > 0) {
        $matches = array_slice($matches, 0, $limit);
    }

    if (($args['fields'] ?? '') === 'ids') {
        return $matches;
    }

    return array_map(static fn (int $id): object => (object) MawTestPosts::$posts[$id], $matches);
}

/**
 * Evaluates the meta_key/meta_value shorthand and a flat meta_query.
 *
 * @param int                 $postId Post to test.
 * @param array<string,mixed> $args   Query arguments.
 * @return bool True when the post satisfies every clause.
 */
function maw_test_posts_match_meta(int $postId, array $args): bool
{
    $clauses = [];

    if (isset($args['meta_key'])) {
        $clauses[] = [
            'key'     => (string) $args['meta_key'],
            'value'   => $args['meta_value'] ?? '',
            'compare' => '=',
        ];
    }

    $relation = 'AND';

    if (isset($args['meta_query']) && is_array($args['meta_query'])) {
        foreach ($args['meta_query'] as $key => $clause) {
            if ($key === 'relation') {
                $relation = strtoupper((string) $clause) === 'OR' ? 'OR' : 'AND';
                continue;
            }

            if (!is_array($clause)) {
                continue;
            }

            if (isset($clause['relation']) || !isset($clause['key'])) {
                throw new RuntimeException('get_posts() double: nested meta_query clauses are not supported');
            }

            $clauses[] = $clause;
        }
    }

    if ($clauses === []) {
        return true;
    }

    $results = [];

    foreach ($clauses as $clause) {
        $key     = (string) $clause['key'];
        $compare = strtoupper((string) ($clause['compare'] ?? '='));
        $stored  = MawTestPosts::$meta[$postId][$key] ?? null;

        switch ($compare) {
            case 'EXISTS':
                $results[] = $stored !== null;
                break;
            case 'NOT EXISTS':
                $results[] = $stored === null;
                break;
            case 'IN':
                $wanted = array_map('strval', (array) ($clause['value'] ?? []));
                $results[] = $stored !== null
                    && array_intersect(array_map('strval', $stored), $wanted) !== [];
                break;
            case '!=':
                $results[] = $stored === null
                    || !in_array((string) ($clause['value'] ?? ''), array_map('strval', $stored), true);
                break;
            case '=':
                $results[] = $stored !== null
                    && in_array((string) ($clause['value'] ?? ''), array_map('strval', $stored), true);
                break;
            default:
                throw new RuntimeException("get_posts() double: unsupported meta compare '" . $compare . "'");
        }
    }

    return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

/**
 * Evaluates a flat tax_query.
 *
 * @param int                 $postId Post to test.
 * @param array<string,mixed> $args   Query arguments.
 * @return bool True when the post satisfies every clause.
 */
function maw_test_posts_match_tax(int $postId, array $args): bool
{
    if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
        return true;
    }

    $relation = 'AND';
    $results  = [];

    foreach ($args['tax_query'] as $key => $clause) {
        if ($key === 'relation') {
            $relation = strtoupper((string) $clause) === 'OR' ? 'OR' : 'AND';
            continue;
        }

        if (!is_array($clause) || !isset($clause['taxonomy'])) {
            continue;
        }

        $taxonomy = (string) $clause['taxonomy'];
        $field    = (string) ($clause['field'] ?? 'term_id');
        $operator = strtoupper((string) ($clause['operator'] ?? 'IN'));
        $wanted   = array_map('strval', (array) ($clause['terms'] ?? []));

        $assigned = MawTestPosts::$objectTerms[$postId][$taxonomy] ?? [];
        $have     = [];

        foreach ($assigned as $termId) {
            foreach (MawTestPosts::$terms[$taxonomy] ?? [] as $slug => $term) {
                if ((int) $term['term_id'] === (int) $termId) {
                    $have[] = $field === 'slug' ? (string) $slug : (string) $term['term_id'];
                }
            }
        }

        $intersects = array_intersect($have, $wanted) !== [];

        if ($operator === 'NOT IN') {
            $results[] = !$intersects;
            continue;
        }

        if ($operator !== 'IN') {
            throw new RuntimeException("get_posts() double: unsupported tax operator '" . $operator . "'");
        }

        $results[] = $intersects;
    }

    if ($results === []) {
        return true;
    }

    return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

/**
 * Orders matched post IDs.
 *
 * @param array<int,int>      $ids  Matching post IDs.
 * @param array<string,mixed> $args Query arguments.
 * @return array<int,int> Ordered IDs.
 */
function maw_test_posts_sort(array $ids, array $args): array
{
    $orderby = strtolower((string) ($args['orderby'] ?? 'id'));
    $descending = strtoupper((string) ($args['order'] ?? 'DESC')) === 'DESC';

    if ($orderby === 'none') {
        return $ids;
    }

    $metaKey = (string) ($args['meta_key'] ?? '');

    usort($ids, static function (int $a, int $b) use ($orderby, $metaKey): int {
        switch ($orderby) {
            case 'menu_order':
                return (int) MawTestPosts::$posts[$a]['menu_order'] <=> (int) MawTestPosts::$posts[$b]['menu_order'];
            case 'date':
                return strcmp(
                    (string) MawTestPosts::$posts[$a]['post_date_gmt'],
                    (string) MawTestPosts::$posts[$b]['post_date_gmt']
                );
            case 'meta_value_num':
                return (float) (MawTestPosts::$meta[$a][$metaKey][0] ?? 0)
                    <=> (float) (MawTestPosts::$meta[$b][$metaKey][0] ?? 0);
            default:
                // Anything unrecognized falls back to ID, matching how a loose
                // orderby behaves in core rather than erroring.
                return $a <=> $b;
        }
    });

    return $descending ? array_reverse($ids) : $ids;
}

/**
 * @param mixed $value Value to slash.
 * @return mixed Slashed value.
 */
function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

/**
 * @param mixed $value Value to unslash.
 * @return mixed Unslashed value.
 */
function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

// ---------------------------------------------------------------------------
// Post meta
// ---------------------------------------------------------------------------

/**
 * @param int    $postId  Post ID.
 * @param string $metaKey Meta key, or '' for every key.
 * @param bool   $single  Whether to return one value.
 * @return mixed Value, list of values, or the full key => values map.
 */
function get_post_meta(int $postId, string $metaKey = '', bool $single = false)
{
    $all = MawTestPosts::$meta[$postId] ?? [];

    if ($metaKey === '') {
        return $all;
    }

    $values = $all[$metaKey] ?? [];

    if ($single) {
        return $values[0] ?? '';
    }

    return $values;
}

/**
 * @param int    $postId    Post ID.
 * @param string $metaKey   Meta key.
 * @param mixed  $metaValue Value to store.
 * @param mixed  $prevValue Ignored.
 * @return bool Always true.
 */
function update_post_meta(int $postId, string $metaKey, $metaValue, $prevValue = ''): bool
{
    MawTestPosts::$meta[$postId][$metaKey] = [
        maw_test_posts_sanitize_meta($postId, $metaKey, wp_unslash($metaValue)),
    ];

    return true;
}

/**
 * Applies a registered meta sanitize callback, as core does on write.
 *
 * register_meta() hooks its sanitize_callback onto the
 * sanitize_{$type}_meta_{$key} filter, which update_metadata() runs before
 * storing. Reproducing that is what makes the registered sanitizers real: a
 * double that stored raw values would let a test "confirm" a date was normalized
 * when nothing had normalized it.
 *
 * @param int    $postId    Post the meta belongs to.
 * @param string $metaKey   Meta key.
 * @param mixed  $metaValue Value being written.
 * @return mixed The sanitized value.
 */
function maw_test_posts_sanitize_meta(int $postId, string $metaKey, $metaValue)
{
    $postType = (string) (MawTestPosts::$posts[$postId]['post_type'] ?? '');
    $callback = MawTestPosts::$registeredMeta[$postType][$metaKey]['sanitize_callback'] ?? null;

    if (!is_callable($callback)) {
        return $metaValue;
    }

    return $callback($metaValue, $metaKey, 'post');
}

/**
 * @param int    $postId    Post ID.
 * @param string $metaKey   Meta key.
 * @param mixed  $metaValue Value to add.
 * @param bool   $unique    Whether to refuse when the key already exists.
 * @return int|false Fake meta ID, or false.
 */
function add_post_meta(int $postId, string $metaKey, $metaValue, bool $unique = false)
{
    if ($unique && isset(MawTestPosts::$meta[$postId][$metaKey])) {
        return false;
    }

    MawTestPosts::$meta[$postId][$metaKey][] = wp_unslash($metaValue);

    return count(MawTestPosts::$meta[$postId][$metaKey]);
}

/**
 * @param int    $postId    Post ID.
 * @param string $metaKey   Meta key.
 * @param mixed  $metaValue Ignored.
 * @return bool True when a row was removed.
 */
function delete_post_meta(int $postId, string $metaKey, $metaValue = ''): bool
{
    if (!isset(MawTestPosts::$meta[$postId][$metaKey])) {
        return false;
    }

    unset(MawTestPosts::$meta[$postId][$metaKey]);

    return true;
}

// ---------------------------------------------------------------------------
// Terms
// ---------------------------------------------------------------------------

/**
 * @param string $term     Term slug or name.
 * @param string $taxonomy Taxonomy name.
 * @return array<string,int>|null The term row, or null when absent.
 */
function term_exists($term, string $taxonomy = '', $parentTerm = null)
{
    $slug = (string) $term;
    $row  = MawTestPosts::$terms[$taxonomy][$slug] ?? null;

    if ($row === null) {
        return null;
    }

    return ['term_id' => (int) $row['term_id'], 'term_taxonomy_id' => (int) $row['term_id']];
}

/**
 * @param string              $term     Term name.
 * @param string              $taxonomy Taxonomy name.
 * @param array<string,mixed> $args     Optional slug override.
 * @return array<string,int>|WP_Error The created term, or WP_Error.
 */
function wp_insert_term(string $term, string $taxonomy, array $args = [])
{
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
    }

    $slug = (string) ($args['slug'] ?? sanitize_title($term));

    if (isset(MawTestPosts::$terms[$taxonomy][$slug])) {
        $error = new WP_Error('term_exists', 'A term with the name provided already exists.');
        // Core carries the existing term id as error data; the production code
        // relies on that to recover from a concurrent insert.
        $error->data = ['term_id' => (int) MawTestPosts::$terms[$taxonomy][$slug]['term_id']];

        return $error;
    }

    $termId = MawTestPosts::$nextTermId++;

    MawTestPosts::$terms[$taxonomy][$slug] = [
        'term_id' => $termId,
        'name'    => $term,
        'slug'    => $slug,
    ];

    return ['term_id' => $termId, 'term_taxonomy_id' => $termId];
}

/**
 * @param int                     $objectId Post ID.
 * @param array<int,int>|int      $terms    Term IDs.
 * @param string                  $taxonomy Taxonomy name.
 * @param bool                    $append   Whether to add rather than replace.
 * @return array<int,int>|WP_Error Assigned term IDs, or WP_Error.
 */
function wp_set_object_terms(int $objectId, $terms, string $taxonomy, bool $append = false)
{
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
    }

    $ids = array_map('intval', (array) $terms);

    if ($append) {
        $existing = MawTestPosts::$objectTerms[$objectId][$taxonomy] ?? [];
        $ids = array_values(array_unique(array_merge($existing, $ids)));
    }

    MawTestPosts::$objectTerms[$objectId][$taxonomy] = $ids;

    return $ids;
}

/**
 * @param int|array<int,int>        $objectIds  Post ID(s).
 * @param string|array<int,string>  $taxonomies Taxonomy name(s).
 * @param array<string,mixed>       $args       Ignored.
 * @return array<int,object> Term rows.
 */
function wp_get_object_terms($objectIds, $taxonomies, array $args = []): array
{
    $ids        = array_map('intval', (array) $objectIds);
    $taxonomies = (array) $taxonomies;
    $out        = [];

    foreach ($ids as $objectId) {
        foreach ($taxonomies as $taxonomy) {
            foreach (MawTestPosts::$objectTerms[$objectId][$taxonomy] ?? [] as $termId) {
                foreach (MawTestPosts::$terms[$taxonomy] ?? [] as $slug => $term) {
                    if ((int) $term['term_id'] === (int) $termId) {
                        $out[] = (object) [
                            'term_id'  => (int) $term['term_id'],
                            'name'     => (string) $term['name'],
                            'slug'     => (string) $slug,
                            'taxonomy' => (string) $taxonomy,
                        ];
                    }
                }
            }
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

/**
 * Schedules a single event, reproducing core's duplicate window.
 *
 * Core refuses a second identical event within ten minutes of an existing one.
 * Reproducing that matters in both directions: a double that always deduplicated
 * would let a test claim a guarantee core does not give, and one that never did
 * would hide that the production code's own wp_next_scheduled() check is the
 * load-bearing part.
 *
 * @param int                $timestamp When to run.
 * @param string             $hook      Hook name.
 * @param array<int,mixed>   $args      Hook arguments.
 * @param bool               $wpError   Ignored.
 * @return bool True when scheduled.
 */
function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wpError = false): bool
{
    $next = wp_next_scheduled($hook, $args);

    if ($next !== false && abs($next - $timestamp) <= 10 * MINUTE_IN_SECONDS) {
        return false;
    }

    MawTestPosts::$cron[$timestamp][$hook][md5(serialize($args))] = [
        'args'      => $args,
        'timestamp' => $timestamp,
    ];

    return true;
}

/**
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Hook arguments.
 * @return int|false Timestamp of the next matching event, or false.
 */
function wp_next_scheduled(string $hook, array $args = [])
{
    $key   = md5(serialize($args));
    $found = false;

    foreach (MawTestPosts::$cron as $timestamp => $hooks) {
        if (isset($hooks[$hook][$key]) && ($found === false || $timestamp < $found)) {
            $found = (int) $timestamp;
        }
    }

    return $found;
}

/**
 * @param int              $timestamp Event timestamp.
 * @param string           $hook      Hook name.
 * @param array<int,mixed> $args      Hook arguments.
 * @return bool True when an event was removed.
 */
function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
{
    $key = md5(serialize($args));

    if (!isset(MawTestPosts::$cron[$timestamp][$hook][$key])) {
        return false;
    }

    unset(MawTestPosts::$cron[$timestamp][$hook][$key]);

    return true;
}

/**
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Ignored; every event for the hook is cleared.
 * @return int How many events were removed.
 */
function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $cleared = 0;

    foreach (MawTestPosts::$cron as $timestamp => $hooks) {
        if (!isset($hooks[$hook])) {
            continue;
        }

        $cleared += count($hooks[$hook]);
        unset(MawTestPosts::$cron[$timestamp][$hook]);
    }

    return $cleared;
}

/**
 * @return bool Whether a cron request is in progress.
 */
function wp_doing_cron(): bool
{
    return MawTestPosts::$doingCron;
}

// ---------------------------------------------------------------------------
// Sanitizers and context
// ---------------------------------------------------------------------------

/**
 * @param string $str Text to sanitize.
 * @return string Tag-free, control-character-free, trimmed text.
 */
function sanitize_text_field(string $str): string
{
    $clean = strip_tags($str);
    $clean = (string) preg_replace('/[\r\n\t]+/', ' ', $clean);
    $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $clean);

    return trim((string) preg_replace('/ {2,}/', ' ', $clean));
}

/**
 * Converts a string to a slug.
 *
 * Underscores are preserved, matching core's sanitize_title_with_dashes(). That
 * detail matters here: playlist names are already sanitize_key()'d, so a term
 * slug derived from one must come out byte-identical or the term assertions
 * would diverge from the playlist meta in a way that looks like a bug.
 *
 * @param string $title    Text to convert.
 * @param string $fallback Returned when the result would be empty.
 * @param string $context  Ignored.
 * @return string The slug.
 */
function sanitize_title(string $title, string $fallback = '', string $context = 'save'): string
{
    $slug = strtolower(strip_tags($title));
    $slug = (string) preg_replace('/[^a-z0-9_\-]+/', '-', $slug);
    $slug = trim((string) preg_replace('/-{2,}/', '-', $slug), '-');

    return $slug === '' ? $fallback : $slug;
}

/**
 * A narrow stand-in for wp_kses_post().
 *
 * Not the real thing and not the identity function either. It strips script and
 * style elements along with their contents, and inline on* event attributes,
 * which is enough to make "the transcript is passed through kses" a meaningful
 * assertion. Other markup is left alone. Do not read anything more into a test
 * that passes here than that the sanitizer ran.
 *
 * @param string $content HTML to filter.
 * @return string Filtered HTML.
 */
function wp_kses_post(string $content): string
{
    $clean = (string) preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $content);
    $clean = (string) preg_replace('#<\s*(script|style)\b[^>]*/?>#i', '', $clean);

    return (string) preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean);
}

/**
 * @param string $capability Capability being checked.
 * @param mixed  ...$args    Ignored.
 * @return bool Whether the current user has it.
 */
function current_user_can(string $capability, ...$args): bool
{
    if ($capability === 'manage_options') {
        return MawTestPosts::$canManageOptions;
    }

    return true;
}

/**
 * @return bool Whether an admin request is in progress.
 */
function is_admin(): bool
{
    return MawTestPosts::$isAdmin;
}

/**
 * @return int The current user ID.
 */
function get_current_user_id(): int
{
    return MawTestPosts::$currentUserId;
}

/**
 * @param string        $tag      Hook name.
 * @param callable|bool $callback Ignored.
 * @return int|false Lowest registered priority, or false.
 */
function has_action(string $tag, $callback = false)
{
    if (empty(MawTestState::$hooks[$tag])) {
        return false;
    }

    return min(array_keys(MawTestState::$hooks[$tag]));
}
