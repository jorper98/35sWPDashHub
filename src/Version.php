<?php

declare(strict_types=1);

namespace S35WpHub;

final class Version
{
    public const VERSION = '0.0.19';

    /** Shown in the dashboard UI (browser title, header). */
    public const APP_DISPLAY_NAME = '35sDash - WordPress Sites Dashboard';

    /** Match `S35_WP_HUB_VERSION` in the companion plugin; bump when you ship a new plugin zip. */
    public const COMPANION_PLUGIN_EXPECTED = '0.0.26';
}
