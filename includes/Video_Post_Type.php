<?php
/**
 * File: includes/Video_Post_Type.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Video_Post_Type
{
    public const POST_TYPE = 'argent_video_asset';

    public static function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels'              => array(
                    'name'          => __('AWVP Videos', 'argentwolf-video-processor'),
                    'singular_name' => __('AWVP Video', 'argentwolf-video-processor'),
                ),
                'public'              => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_admin_bar'   => false,
                'show_in_nav_menus'   => false,
                'show_in_rest'        => false,
                'rewrite'             => false,
                'query_var'           => false,
                'hierarchical'        => false,
                'delete_with_user'    => false,
                'can_export'          => false,
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'supports'            => array('title', 'editor', 'author', 'custom-fields'),
            )
        );
    }
}

// EOF: includes/Video_Post_Type.php
