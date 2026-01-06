<?php
/**
 * Dynamic Web App Manifest
 * Generates manifest.json with theme-aware colors
 * 
 * IMPORTANT: This file replaces manifest.json
 * Make sure to update the link in index.php: <link rel="manifest" href="manifest.php">
 */

require_once __DIR__ . '/config.php';

// Get app config and theme
$appConfig = gm_get_app_config();
$themeMode = gm_get_user_theme_mode();
$themeColors = gm_get_theme_colors($themeMode);

// Set JSON content type
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache, must-revalidate');

// Build manifest array
$manifest = [
    'name' => $appConfig['appName'],
    'short_name' => $appConfig['appShortName'],
    'description' => 'Track vehicle maintenance, service history, and upcoming reminders',
    'start_url' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'theme_color' => $themeColors['theme_color'],
    'background_color' => $themeColors['background_color'],
    'scope' => './',
    'lang' => 'en-US',
    'categories' => ['utilities', 'lifestyle'],
    'icons' => [
        [
            'src' => 'assets/images/icon-32.png',
            'sizes' => '32x32',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => 'assets/images/icon-64.png',
            'sizes' => '64x64',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => 'assets/images/icon-128.png',
            'sizes' => '128x128',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => 'assets/images/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => 'assets/images/icon-256.png',
            'sizes' => '256x256',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => 'assets/images/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => 'assets/images/icon-1024.png',
            'sizes' => '1024x1024',
            'type' => 'image/png',
            'purpose' => 'any'
        ]
    ],
    'screenshots' => [],
    'shortcuts' => [
        [
            'name' => 'Dashboard',
            'short_name' => 'Dashboard',
            'description' => 'View your vehicle dashboard',
            'url' => './#dashboard',
            'icons' => [
                [
                    'src' => 'assets/images/icon-192.png',
                    'sizes' => '192x192'
                ]
            ]
        ],
        [
            'name' => 'Reminders',
            'short_name' => 'Reminders',
            'description' => 'Check maintenance reminders',
            'url' => './#reminders',
            'icons' => [
                [
                    'src' => 'assets/images/icon-192.png',
                    'sizes' => '192x192'
                ]
            ]
        ]
    ],
    'related_applications' => [],
    'prefer_related_applications' => false
];

// Output JSON
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
