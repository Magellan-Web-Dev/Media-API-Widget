<?php
/**
 * Thumbnail selection tests for the YouTube parse loop.
 *
 * `snippet.thumbnails` is ragged: YouTube only includes a size it actually
 * generated, so maxres and standard are routinely absent and the whole object
 * can be missing. Every case here covers one shape the parse loop must handle
 * without reading a key that is not there.
 */

declare(strict_types=1);

return [

    'maxres wins when it is present' => static function (): void {
        maw_queue_json(maw_youtube_thumbnail_page([
            [
                'maxres'   => maw_thumbnail('maxres'),
                'standard' => maw_thumbnail('standard'),
                'high'     => maw_thumbnail('high'),
                'medium'   => maw_thumbnail('medium'),
                'default'  => maw_thumbnail('default'),
            ],
        ]));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count($state['parsedData']), 'the item is kept');
        maw_assert_same(
            maw_thumbnail('maxres'),
            $state['parsedData'][0]['thumbnail'],
            'maxres is chosen over every lower size'
        );
    },

    'the preference order falls through each missing size' => static function (): void {
        // Item 1 has no maxres, item 2 has neither maxres nor standard, and so
        // on, so one page exercises every rung of the fallback ladder.
        maw_queue_json(maw_youtube_thumbnail_page([
            [
                'standard' => maw_thumbnail('standard', 1),
                'high'     => maw_thumbnail('high', 1),
                'medium'   => maw_thumbnail('medium', 1),
                'default'  => maw_thumbnail('default', 1),
            ],
            [
                'high'    => maw_thumbnail('high', 2),
                'medium'  => maw_thumbnail('medium', 2),
                'default' => maw_thumbnail('default', 2),
            ],
            [
                'medium'  => maw_thumbnail('medium', 3),
                'default' => maw_thumbnail('default', 3),
            ],
        ]));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(3, count($state['parsedData']), 'all three items are kept');
        maw_assert_same(
            maw_thumbnail('standard', 1),
            $state['parsedData'][0]['thumbnail'],
            'a video without maxres falls back to standard'
        );
        maw_assert_same(
            maw_thumbnail('high', 2),
            $state['parsedData'][1]['thumbnail'],
            'a video without maxres or standard falls back to high'
        );
        maw_assert_same(
            maw_thumbnail('medium', 3),
            $state['parsedData'][2]['thumbnail'],
            'a video down to medium falls back to medium'
        );
    },

    'a video with only default uses default as its thumbnail' => static function (): void {
        maw_queue_json(maw_youtube_thumbnail_page([
            ['default' => maw_thumbnail('default')],
        ]));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count($state['parsedData']), 'the item is kept rather than dropped');
        maw_assert_same(
            maw_thumbnail('default'),
            $state['parsedData'][0]['thumbnail'],
            'the default size is assigned to thumbnail, not to a stray default key'
        );
        maw_assert(
            !array_key_exists('default', $state['parsedData'][0]),
            'no stray default key is written onto the item'
        );
    },

    'a video with no usable thumbnail is dropped' => static function (): void {
        maw_queue_json(maw_youtube_thumbnail_page([
            // A snippet with no thumbnails key at all.
            null,
            // A thumbnails object that is present but empty.
            [],
            // Present keys holding nothing usable.
            ['maxres' => [], 'default' => null],
            // One healthy item, so the drop is visibly selective.
            ['high' => maw_thumbnail('high', 4)],
        ]));

        $state = maw_run_youtube_load(maw_youtube_config());

        maw_assert_same(1, count($state['parsedData']), 'only the item with a usable thumbnail survives');
        maw_assert_same('Episode 4', $state['parsedData'][0]['title'], 'the surviving item is the healthy one');
        maw_assert_same(
            maw_thumbnail('high', 4),
            $state['parsedData'][0]['thumbnail'],
            'the surviving item keeps its thumbnail'
        );
    },

    'missing thumbnail sizes emit no warnings or notices' => static function (): void {
        maw_queue_json(maw_youtube_thumbnail_page([
            null,
            [],
            ['default' => maw_thumbnail('default', 2)],
            ['medium' => maw_thumbnail('medium', 3), 'default' => maw_thumbnail('default', 3)],
            ['maxres' => maw_thumbnail('maxres', 4)],
        ]));

        $captured = maw_capture_php_errors(static fn (): array => maw_run_youtube_load(maw_youtube_config()));

        maw_assert_same([], $captured['errors'], 'no PHP diagnostic is raised while parsing ragged thumbnails');
        maw_assert_same(3, count($captured['result']['parsedData']), 'the three items with thumbnails are kept');
    },
];
