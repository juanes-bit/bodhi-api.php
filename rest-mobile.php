<?php
/**
 * Plugin Name: Bodhi Mobile REST
 * Description: Endpoints REST para app móvil (nonce + mis cursos)
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
  exit;
}

add_action('rest_api_init', function () {
  register_rest_route('bodhi/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in();
    },
    'callback' => function () {
      return ['nonce' => wp_create_nonce('wp_rest')];
    },
  ]);

  register_rest_route('bodhi/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      // No llames a check_ajax_referer(): WP valida el nonce REST automáticamente.
      return is_user_logged_in();
    },
    'callback' => function (WP_REST_Request $req) {
      $per_page = (int) $req->get_param('per_page');
      if ($per_page <= 0) {
        $per_page = 10;
      }

      $course_post_type = 'tva_course';
      if (function_exists('bodhi_get_course_pt')) {
        $course_post_type = bodhi_get_course_pt();
      }

      $query = new WP_Query([
        'post_type'      => $course_post_type,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
      ]);

      $items = [];
      $current_user_id = get_current_user_id();

      if ($query->have_posts()) {
        while ($query->have_posts()) {
          $query->the_post();

          $course_id = get_the_ID();
          $title = get_the_title();
          $image = get_the_post_thumbnail_url($course_id, 'medium');
          $has_access = false;

          if (function_exists('tva_access_manager')) {
            $manager = tva_access_manager();
            if (is_object($manager)) {
              if (method_exists($manager, 'has_access')) {
                $has_access = (bool) $manager->has_access($course_id, $current_user_id);
              } elseif (method_exists($manager, 'user_has_access')) {
                $has_access = (bool) $manager->user_has_access($course_id, $current_user_id);
              }
            }
          }

          if (!$has_access && current_user_can('manage_options')) {
            $has_access = true;
          }

          $items[] = [
            'id'            => $course_id,
            'title'         => $title,
            'image'         => $image ?: null,
            'percent'       => 0,
            'access'        => $has_access ? 'owned' : 'locked',
            'is_owned'      => $has_access,
            'course_number' => '',
          ];
        }
      }

      wp_reset_postdata();

      $owned_count = 0;
      foreach ($items as $item) {
        if (!empty($item['is_owned'])) {
          $owned_count++;
        }
      }

      return rest_ensure_response([
        'items' => $items,
        'total' => count($items),
        'owned' => $owned_count,
      ]);
    },
  ]);
});

