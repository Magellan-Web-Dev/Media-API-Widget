<?php
namespace MediaApiWidget\Admin;

use MediaApiWidget\Config\Options;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders and processes the main Settings admin page.
 *
 * Manages two data sets stored in wp_options:
 * - Media items — the list of YouTube playlists and podcast feeds to fetch.
 * - Shortcode fields — global key/value pairs referenced by the
 *   [media-api-widget field=""] shortcode and the {{field_name}} syntax.
 *
 * Handles both the "Save changes" and "Clear plugin cache" form actions.
 * On save, all existing and new transients, backup-window options, and the
 * cache-invalidation cookie are purged so the front end fetches fresh data
 * on the next page load.
 */
final class SettingsPage
{
    /**
     * Processes the Settings page form POST.
     *
     * Guards against unauthorized access via capability check and nonce
     * verification. Handles two submit buttons:
     * - maw_save       — persists media items and shortcode fields then clears cache.
     * - maw_clear_cache — clears cache without changing stored settings.
     *
     * After processing, redirects to the Settings page with a `maw_status`
     * query parameter so the render pass can display a success notice without
     * re-processing on a browser refresh.
     *
     * @return void
     */
    public function handlePost(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['maw_save']) && !isset($_POST['maw_clear_cache'])) {
            return;
        }

        if (!check_admin_referer('maw_save_settings', 'maw_nonce')) {
            return;
        }

        $existingItems = Options::getMediaItems();
        $existingCookieName = Options::getCookieName();
        $status = null;

        if (isset($_POST['maw_save'])) {
            $items = $this->sanitizeItems($_POST['maw_items'] ?? []);
            Options::setMediaItems($items);
            $shortcodes = $this->sanitizeShortcodes($_POST['maw_shortcodes'] ?? []);
            Options::setShortcodes($shortcodes);

            $this->clearCache(array_merge($existingItems, $items), [$existingCookieName]);
            $status = 'saved';
        }

        if (isset($_POST['maw_clear_cache'])) {
            $this->clearCache($existingItems, [$existingCookieName]);
            $status = 'cleared';
        }

        if ($status) {
            $redirectUrl = add_query_arg('maw_status', $status, menu_page_url(Menu::SLUG, false));
            wp_safe_redirect($redirectUrl);
            exit;
        }
    }

    /**
     * Renders the full Settings admin page HTML.
     *
     * Reads the `maw_status` query parameter from the redirect set by
     * {@see self::handlePost()} and displays an appropriate admin notice.
     * Outputs the main form with two sections:
     * - Media Items table — rows for each configured YouTube/podcast entry.
     * - Shortcodes table — rows for each stored key/value shortcode field.
     *
     * Also emits two `<script type="text/template">` blocks used by the admin
     * JavaScript to clone new rows without a page reload.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $message = null;
        $messageClass = 'notice-success';
        $status = isset($_GET['maw_status']) ? sanitize_key((string) $_GET['maw_status']) : '';
        if ($status === 'saved') {
            $message = 'Settings saved.';
        } elseif ($status === 'cleared') {
            $message = 'Plugin cache cleared.';
        }

        $items = Options::getMediaItems();
        $shortcodes = Options::getShortcodes();
        ?>
        <div class="wrap maw-wrap">
            <h1>Media API</h1>
            <p class="maw-note">Define YouTube playlists and Podcast feeds here. The front-end rendering remains driven by the existing widget JS, but you no longer need WPCode constants.</p>

            <?php if ($message) : ?>
                <div class="notice <?= esc_attr($messageClass) ?> is-dismissible"><p><?= esc_html($message) ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('maw_save_settings', 'maw_nonce'); ?>

                <h2>Media Items</h2>
                <p class="maw-actions">
                    <a href="#" class="button button-secondary" data-maw-add="youtube">Add YouTube playlist</a>
                    <a href="#" class="button button-secondary" data-maw-add="podcast">Add Podcast</a>
                </p>

                <table class="widefat striped maw-table"><thead><tr>
                    <th style="width:120px;">Type</th>
                    <th style="width:220px;">Name (playlist_name)</th>
                    <th>Settings</th>
                    <th style="width:90px;">Actions</th>
                </tr></thead><tbody id="maw-table-body">
                    <?php foreach ($items as $i => $item) : ?>
                        <?php $this->renderRow($i, $item); ?>
                    <?php endforeach; ?>
                </tbody></table>

                <h2>Shortcodes</h2>
                <p class="maw-note">Shortcodes will be [media-api-widget] with an attribute of "field" corresponding to the field name defined.  These can also be accessed in the [media-api-widget-render] for podcast player styling, with its value being the shortcode field value wrapped in double quotes, such as podcastplayercolor="{{podcastplayercolor}}"].</p>
                <p class="maw-actions">
                    <a href="#" class="button button-secondary" data-maw-shortcode-add="1">Add shortcode field</a>
                </p>

                <table class="widefat striped maw-table maw-shortcode-table"><thead><tr>
                    <th style="width:220px;">Field</th>
                    <th>Value</th>
                    <th style="width:90px;">Actions</th>
                </tr></thead><tbody id="maw-shortcode-table-body">
                    <?php foreach ($shortcodes as $i => $shortcode) : ?>
                        <?php $this->renderShortcodeRow($i, $shortcode); ?>
                    <?php endforeach; ?>
                </tbody></table>

                <p>
                    <button type="submit" class="button button-primary" name="maw_save" value="1">Save changes</button>
                    <button type="submit" class="button button-secondary" name="maw_clear_cache" value="1">Clear plugin cache</button>
                </p>
            </form>

            <script type="text/template" id="maw-row-template">
                <?php $this->renderRow('__INDEX__', ['type' => '__TYPE__'], true); ?>
            </script>

            <script type="text/template" id="maw-shortcode-row-template">
                <?php $this->renderShortcodeRow('__INDEX__', ['field' => '', 'value' => ''], true); ?>
            </script>
        </div>
        <?php
    }

    /**
     * Outputs a single media item table row.
     *
     * Renders a `<tr>` containing type selector, playlist name input, and
     * type-specific settings fields. YouTube fields (Playlist ID, API key,
     * sort mode, load-full toggle) are shown or hidden via inline `display:none`
     * styles depending on the current type, and the admin JS toggles them when
     * the type selector changes. Podcast fields (platform, media data) are
     * handled symmetrically.
     *
     * When `$unSaved` is true the row is being rendered into a `<script>` template
     * for JavaScript cloning; disabled styling is suppressed and `name` attributes
     * use the `__INDEX__` placeholder so JS can renumber them.
     *
     * @param int|string          $index   Row index used for form field names, or '__INDEX__' for the JS template.
     * @param array<string,mixed> $item    Saved media item config array.
     * @param bool                $unSaved True when rendering the JS clone template (suppresses disabled styles).
     * @return void
     */
    private function renderRow($index, array $item, bool $unSaved = false): void
    {
        $type = isset($item['type']) ? (string) $item['type'] : 'youtube';

        $playlistName    = $item['playlist_name'] ?? '';
        $apiKey          = $item['api_key'] ?? '';
        $mediaData       = $item['media_data'] ?? '';
        $sortMode        = $item['sort_mode'] ?? 'normal';
        $loadFull        = !empty($item['load_full_playlist']);
        $podcastPlatform = $item['podcast_platform'] ?? 'custom';

        // Names are set server-side so saving works even if admin JS fails to load.
        // data-maw-name is used by JS to renumber indices when rows are added/removed.
        $nameBase = is_numeric($index) ? 'maw_items[' . (int) $index . ']' : 'maw_items[__INDEX__]';
        $dataBase = 'maw_items[__INDEX__]';

        $disabledStyling = $unSaved ? '' : 'maw-row-disabled';
        $ytStyle         = ($type === 'youtube') ? '' : ' style="display:none"';
        $podStyle        = ($type === 'podcast') ? '' : ' style="display:none"';
        $ytDisabled      = ($type === 'youtube') ? '' : ' disabled';
        $podDisabled     = ($type === 'podcast') ? '' : ' disabled';
        ?>
        <tr data-maw-row="1" class="maw-row">
            <td>
                <label>Type</label>
                <select class="<?= $disabledStyling ?>" name="<?= esc_attr($nameBase . '[type]') ?>" data-maw-type-select="1" data-maw-name="<?= esc_attr($dataBase . '[type]') ?>" required>
                    <?php foreach (['youtube' => 'YouTube', 'podcast' => 'Podcast'] as $v => $label) : ?>
                        <?php $sel = ($type === $v) ? 'selected' : ''; ?>
                        <option value="<?= esc_attr($v) ?>" <?= $sel ?>><?= esc_html($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>

            <td>
                <label>Name (playlist_name)</label>
                <input class="<?= $disabledStyling ?>" type="text" name="<?= esc_attr($nameBase . '[playlist_name]') ?>" data-maw-name="<?= esc_attr($dataBase . '[playlist_name]') ?>" value="<?= esc_attr($playlistName) ?>" placeholder="e.g. show_name" required /></td>

            <td>
                <div data-maw-fields="youtube"<?= $ytStyle ?>>
                    <p><label>Playlist ID<br><input type="text" name="<?= esc_attr($nameBase . '[media_data]') ?>" data-maw-name="<?= esc_attr($dataBase . '[media_data]') ?>" value="<?= esc_attr($type === 'youtube' ? $mediaData : '') ?>"<?= $ytDisabled ?> /></label></p>
                    <p><label>API key<br><input type="text" name="<?= esc_attr($nameBase . '[api_key]') ?>" data-maw-name="<?= esc_attr($dataBase . '[api_key]') ?>" value="<?= esc_attr($type === 'youtube' ? $apiKey : '') ?>" /></label></p>
                    <p><label>Sort mode (For TV shows from the Magellan Youtube, use "Number in title")<br><select name="<?= esc_attr($nameBase . '[sort_mode]') ?>" data-maw-name="<?= esc_attr($dataBase . '[sort_mode]') ?>">
                        <?php foreach (['normal' => 'Normal', 'number_in_title' => 'Number in title'] as $v => $label) : ?>
                            <?php $sel = (($type === 'youtube' ? $sortMode : 'normal') === $v) ? 'selected' : ''; ?>
                            <option value="<?= esc_attr($v) ?>" <?= $sel ?>><?= esc_html($label) ?></option>
                        <?php endforeach; ?>
                    </select></label></p>
                    <p><label><input type="checkbox" name="<?= esc_attr($nameBase . '[load_full_playlist]') ?>" data-maw-name="<?= esc_attr($dataBase . '[load_full_playlist]') ?>" value="1" <?= ($type === 'youtube' && $loadFull) ? 'checked' : '' ?> /> Load full playlist (otherwise first 6)</label></p>
                </div>

                <div data-maw-fields="podcast"<?= $podStyle ?>>
                    <p><label>Platform<br><select name="<?= esc_attr($nameBase . '[podcast_platform]') ?>" data-maw-name="<?= esc_attr($dataBase . '[podcast_platform]') ?>">
                        <?php foreach (['custom' => 'Direct RSS URL', 'omny' => 'Omny', 'soundcloud' => 'SoundCloud', 'buzzsprout' => 'Buzzsprout', 'other' => 'Other (Apple lookup)', 'embed' => 'Embed URL'] as $v => $label) : ?>
                            <?php $sel = (($type === 'podcast' ? $podcastPlatform : 'custom') === $v) ? 'selected' : ''; ?>
                            <option value="<?= esc_attr($v) ?>" <?= $sel ?>><?= esc_html($label) ?></option>
                        <?php endforeach; ?>
                    </select></label></p>
                    <p><label>RSS URL / ID / Embed URL (media_data)<br><input type="text" name="<?= esc_attr($nameBase . '[media_data]') ?>" data-maw-name="<?= esc_attr($dataBase . '[media_data]') ?>" value="<?= esc_attr($type === 'podcast' ? $mediaData : '') ?>"<?= $podDisabled ?> /></label></p>
                    <p class="description">For "Direct RSS URL", paste the RSS feed URL. For Omny/SoundCloud/Buzzsprout/Other, use the Apple podcast ID (numeric). For "Embed URL", paste the embed URL.</p>
                </div>
            </td>

            <td><a href="#" class="button button-link-delete" data-maw-delete="1">Remove</a></td>
        </tr>
        <?php
    }

    /**
     * Outputs a single shortcode field table row.
     *
     * Renders a `<tr>` with field name and value inputs. Rows whose field name
     * matches one of the eight locked default fields (via
     * {@see Options::isDefaultShortcodeField()}) are rendered with the field
     * input set to `readonly` and without the Remove button, preventing
     * accidental deletion of the podcast player styling fields.
     *
     * @param int|string          $index   Row index used for form field names, or '__INDEX__' for the JS template.
     * @param array<string,mixed> $item    Shortcode field array with 'field' and 'value' keys.
     * @param bool                $unSaved True when rendering the JS clone template (no locked-field check performed).
     * @return void
     */
    private function renderShortcodeRow($index, array $item, bool $unSaved = false): void
    {
        $field    = $item['field'] ?? '';
        $value    = $item['value'] ?? '';
        $isPreset = !$unSaved && Options::isDefaultShortcodeField((string) $field);

        $nameBase = is_numeric($index) ? 'maw_shortcodes[' . (int) $index . ']' : 'maw_shortcodes[__INDEX__]';
        $dataBase = 'maw_shortcodes[__INDEX__]';
        ?>
        <tr data-maw-shortcode-row="1" class="maw-row maw-shortcode-row"<?= $isPreset ? ' data-maw-shortcode-locked="1"' : '' ?>>
            <td class="maw-shortcode-field">
                <label>Field</label>
                <input type="text" name="<?= esc_attr($nameBase . '[field]') ?>" data-maw-shortcode-name="<?= esc_attr($dataBase . '[field]') ?>" value="<?= esc_attr($field) ?>" placeholder="e.g. hero_title" <?= $isPreset ? 'readonly="readonly"' : '' ?> />
            </td>
            <td class="maw-value-field">
                <label>Value</label>
                <input type="text" name="<?= esc_attr($nameBase . '[value]') ?>" data-maw-shortcode-name="<?= esc_attr($dataBase . '[value]') ?>" value="<?= esc_attr($value) ?>" />
            </td>
            <td>
                <?php if (!$isPreset) : ?>
                    <a href="#" class="button button-link-delete" data-maw-shortcode-delete="1">Remove</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Sanitizes raw form data for the media items array.
     *
     * Iterates the submitted array, discarding any entry whose type is not
     * 'youtube' or 'podcast'. Playlist names are run through sanitize_key();
     * empty names default to 'unnamed' to preserve legacy behavior. All other
     * string fields are sanitized with sanitize_text_field() and trim(). The
     * load_full_playlist flag is cast to bool.
     *
     * @param mixed $raw The raw $_POST value for 'maw_items'; expected to be an array.
     * @return array<int, array<string,mixed>> Sanitized media item config arrays.
     */
    private function sanitizeItems($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = isset($item['type']) ? sanitize_key((string) $item['type']) : 'youtube';
            if (!in_array($type, ['youtube', 'podcast'], true)) {
                continue;
            }

            $playlistName = isset($item['playlist_name']) ? sanitize_key((string) $item['playlist_name']) : '';
            if ($playlistName === '') {
                // keep unnamed to maintain legacy default behavior
                $playlistName = 'unnamed';
            }

            $row = [
                'type' => $type,
                'playlist_name' => $playlistName,
                'media_data' => isset($item['media_data']) ? trim(sanitize_text_field((string) $item['media_data'])) : null,
                'api_key' => isset($item['api_key']) ? trim(sanitize_text_field((string) $item['api_key'])) : null,
                'sort_mode' => isset($item['sort_mode']) ? sanitize_key((string) $item['sort_mode']) : 'normal',
                'load_full_playlist' => !empty($item['load_full_playlist']),
                'podcast_platform' => isset($item['podcast_platform']) ? sanitize_key((string) $item['podcast_platform']) : 'custom',
            ];

            // Basic required fields (still allow saving, but front-end will error-log like before).
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Sanitizes raw form data for the shortcode fields array.
     *
     * Iterates the submitted array, discarding any entry whose field key is
     * empty after sanitize_key(). Values are sanitized with sanitize_text_field()
     * and trim(). Duplicates are not deduplicated here; the merge logic in
     * {@see Options::setShortcodes()} handles that.
     *
     * @param mixed $raw The raw $_POST value for 'maw_shortcodes'; expected to be an array.
     * @return array<int, array<string,mixed>> Sanitized shortcode field arrays.
     */
    private function sanitizeShortcodes($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $field = isset($item['field']) ? sanitize_key((string) $item['field']) : '';
            if ($field === '') {
                continue;
            }

            $out[] = [
                'field' => $field,
                'value' => isset($item['value']) ? trim(sanitize_text_field((string) $item['value'])) : '',
            ];
        }

        return $out;
    }

    /**
     * Clears all cached data for the supplied media items and cookie names.
     *
     * For each media item, deletes:
     * - The primary data transient (`{type}_{playlist_name}`).
     * - YouTube-specific transients: error flag and request-in-progress lock.
     * - The `maw_yt_last_fetched_{playlist_name}` wp_options entry that guards
     *   the backup-window rate limit.
     *
     * After clearing transients and options, expires each cookie by setting it
     * with a timestamp in the past, and also removes it from `$_COOKIE` so the
     * current request immediately sees the cleared state. Finally, calls
     * flush_rewrite_rules() to ensure the podcast player route is still registered.
     *
     * @param array<int, array<string,mixed>> $items       Media item config arrays to clear cache for.
     * @param array<int, string>              $cookieNames Cookie names to expire.
     * @return void
     */
    private function clearCache(array $items, array $cookieNames): void
    {
        $keys = [];
        $optionKeys = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = isset($item['type']) ? sanitize_key((string) $item['type']) : '';
            $playlistName = isset($item['playlist_name']) ? sanitize_key((string) $item['playlist_name']) : '';

            if ($type !== '' && $playlistName !== '') {
                $keys[] = $type . '_' . $playlistName;
            }

            if ($type === 'youtube' && $playlistName !== '') {
                $keys[] = $playlistName . '_youtube_error';
                $keys[] = $playlistName . '_youtube_request_in_progress';
                $optionKeys[] = 'maw_yt_last_fetched_' . $playlistName;
            }
        }

        foreach (array_unique($keys) as $key) {
            delete_transient($key);
        }

        foreach (array_unique($optionKeys) as $optionKey) {
            delete_option($optionKey);
        }

        foreach (array_unique($cookieNames) as $cookieName) {
            if ($cookieName === '') {
                continue;
            }

            setcookie($cookieName, '', time() - 3600, '/');
            unset($_COOKIE[$cookieName]);
        }

        flush_rewrite_rules();
    }
}
