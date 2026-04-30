<?php
if (!defined('ABSPATH')) { exit; }

$vars = (new \MediaApiWidget\PodcastPlayer\DataParams())->build();
extract($vars, EXTR_SKIP);

$base_url = MAW_PLUGIN_URL . 'assets/podcast-player';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="robots" content="noindex, follow">

    <base href="<?= esc_url($base_url); ?>/">
    <link rel="stylesheet" href="<?= esc_url($base_url); ?>/style.css">
    <link rel="icon" type="image/jpeg" href="<?= esc_url($podcast_image); ?>">
    <title><?= $parsed_rss_feed && $parsed_rss_feed->channel ? esc_html((string) $parsed_rss_feed->channel->title) : 'Podcast Player'; ?></title>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=<?= rawurlencode($style['font']); ?>:ital,wght@0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap');
        * { color: <?= esc_attr($style['textColor']); ?>; font-family: '<?= esc_attr($style['font']); ?>', sans-serif; }
        body::after { opacity: <?= $style['mode'] === '#fff' ? '0.88' : '0.8'; ?>; }
        :root {
            --transition: 0.25s;
            --theme: <?= esc_attr($style['mode']); ?>;
            --color1: <?= esc_attr($style['color1']); ?>;
            --progressBarColor: <?= esc_attr($style['progressBarColor']); ?>;
            --highlightcolor: <?= esc_attr($style['highlight']); ?>;
            --scrollbarcolor : <?= esc_attr($style['scrollbar']); ?>;
        }
        .play-icon *, .pause-icon * { fill: <?= esc_attr($style['playButton']); ?> !important; }
    </style>
    <style id="background-image-styling">
        body::before { background-image: url(<?= esc_url($podcast_image); ?>); }
    </style>
</head>
<body>
    <svg style="display: none">
        <symbol id="play-icon-symbol">
            <defs>
                <clipPath id="clip-path" transform="translate(-264.41 -245.59)">
                    <rect x="264.41" y="245.59" width="145.2" height="145.2"></rect>
                </clipPath>
                <clipPath id="clip-path-3" transform="translate(-264.41 -245.59)">
                    <rect x="255.41" y="238.59" width="163.2" height="153.2"></rect>
                </clipPath>
            </defs>
            <path d="M378.93,318.19,311,357.4V279Zm30.68,0a72.6,72.6,0,1,0-72.6,72.6,72.6,72.6,0,0,0,72.6-72.6" transform="translate(-264.41 -245.59)"></path>
        </symbol>
    </svg>

    <?php if ($error_loading_rss): ?>
        <div class="error-msg">
            <h1>Error Loading Podcast</h1>
            <h4><?= esc_html((string) $err_msg); ?></h4>
        </div>
    <?php else: ?>
        <main>
            <header>
                <?php if (!empty($channel->link)): ?>
                    <a href="<?= esc_url((string) $channel->link); ?>" target="_blank" rel="nofollow">
                        <img src="<?= esc_url($podcast_image); ?>" alt="<?= esc_attr((string) $channel->title); ?>" id="current-episode-image">
                    </a>
                <?php else: ?>
                    <img src="<?= esc_url($podcast_image); ?>" alt="<?= esc_attr((string) $channel->title); ?>" id="current-episode-image">
                <?php endif; ?>
                <div class="player-control-container">
                    <div class="player-control-title-links">
                        <h3><?= esc_html((string) $channel->title); ?></h3>
                    </div>
                    <div class="play-episode-container">
                        <div id="play-pause-button-icons">
                            <svg class="play-icon" viewBox="0 0 145.2 145.2">
                                <use href="#play-icon-symbol"></use>
                            </svg>
                            <svg class="pause-icon" viewBox="0 0 145.2 145.2">
                                <path d="M-132,798.77a72.61,72.61,0,0,1-72.6,72.6,72.6,72.6,0,0,1-72.6-72.6,72.59,72.59,0,0,1,72.6-72.6A72.6,72.6,0,0,1-132,798.77Zm-84-38.3h-19.41v75.79H-216Zm41.3,0h-19.41v75.79h19.41Z" transform="translate(277.21 -726.17)"/>
                            </svg>
                            <audio id="audio-play" src="<?= esc_url((string) $audio_data->url); ?>"></audio>
                        </div>
                        <div class="play-title-time-text">
                            <h5 id="episode-selected-title"><?= esc_html((string) $episode_selected->title); ?></h5>
                            <div id="episode-selected-time">
                                <h5 id="current-episode-time"></h5>
                                <h5> / </h5>
                                <h5 id="current-episode-duration"></h5>
                            </div>
                        </div>
                    </div>
                    <div class="player-episode-description-container">
                        <h6 id="player-episode-description"><?= esc_html((string) $episode_selected->description); ?></h6>
                    </div>
                    <div id="play-progress-bar"><div id="progress-duration-filler" style="right: 100%;"></div></div>
                </div>
            </header>

            <?php if ($single_episode === 'false'): ?>
                <ol id="episodes-list">
                    <?php foreach($episodes as $episode): ?>
                        <li data-episodeid="<?= esc_attr((string) $episode->guid); ?>">
                            <div class="episode-list-image-play">
                                <img src="<?= esc_url(isset($episode->image) ? (string) $episode->image : $podcast_image); ?>" alt="<?= esc_attr((string) $episode->title); ?>">
                                <svg class="list-item-play-icon" viewBox="0 0 145.2 145.2">
                                    <use href="#play-icon-symbol"></use>
                                </svg>
                            </div>
                            <div class="episode-list-title-description">
                                <h5><?= esc_html((string) $episode->title); ?></h5>
                                <?= !strlen(trim((string) $episode->description)) ? '' : '<p>' . esc_html((string) $episode->description) . '</p>'; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </main>

        <script>
            const startingEpisodeId = "<?= esc_js((string) $starting_episode_id); ?>";
            const rssData = <?= $rss_data ?: 'null'; ?>;
            const fullPlayer = "<?= esc_js((string) $single_episode); ?>" === "false";
        </script>
        <script src="<?= esc_url($base_url); ?>/scripts/rss_data.js"></script>
        <script src="<?= esc_url($base_url); ?>/scripts/element_selectors.js"></script>
        <script src="<?= esc_url($base_url); ?>/scripts/event_handlers.js"></script>
    <?php endif; ?>
</body>
</html>
