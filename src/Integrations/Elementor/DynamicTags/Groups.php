<?php
namespace MediaApiWidget\Integrations\Elementor\DynamicTags;

if (!defined('ABSPATH')) { exit; }

/**
 * Dynamic tag group shared by the Media API tags.
 */
final class Groups
{
    /** Group name under which the tags appear in Elementor's dynamic tag list. */
    public const GROUP = 'media-api';
}
