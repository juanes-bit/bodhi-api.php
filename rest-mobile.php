<?php
/**
 * REST móvil Bodhi (contrato estable para la app)
 * Ruta base: /wp-json/bodhi-mobile/v1/...
 */

if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function () {

  // 0) Verifica sesión (back-compat con apps actuales)
  register_rest_route('bodhi-mobile/v1', '/me', [
    'methods'  => 'GET',
    'permission_callback' => 'bodhi_rest_permission_cookie',
    'callback' => function (WP_REST_Request $req) {
      $u = wp_get_current_user();
      if (!$u || !$u->ID) {
        return new WP_Error('rest_forbidden', 'No autorizado', ['status' => 401]);
      }
      return rest_ensure_response([
        'id'        => (int) $u->ID,
        'email'     => $u->user_email,
        'name'      => $u->display_name,
        'roles'     => $u->roles,
        'logged_in' => true,
      ]);
    },
  ]);

  // 1) Nonce opcional (para clientes que lo necesiten)
  register_rest_route('bodhi-mobile/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => 'bodhi_rest_permission_cookie',
    'callback' => function () {
      return ['nonce' => wp_create_nonce('wp_rest')];
    },
  ]);

  // 2) Mis cursos con marca de propiedad por item
  register_rest_route('bodhi-mobile/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {

      $user_id = get_current_user_id();
      if (!$user_id) {
        return new WP_Error('rest_forbidden', 'No estás conectado.', ['status' => 401]);
      }

      $inner = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $resp = rest_do_request($inner);
      if (is_wp_error($resp)) {
        return $resp;
      }
      $data = $resp->get_data();
      $src  = is_array($data['items'] ?? null) ? $data['items'] : [];

      $items = [];
      $owned_ids = [];

      foreach ($src as $ci) {
        $id = intval($ci['id'] ?? $ci['course_id'] ?? $ci['post_id'] ?? 0);
        if (!$id) {
          continue;
        }

        $title = (string) ($ci['title'] ?? $ci['name'] ?? 'Curso #' . $id);
        $image = (string) ($ci['thumb'] ?? $ci['image'] ?? '');
        $items[] = [
          'id'            => $id,
          'courseId'      => $id,
          'title'         => $title,
          'image'         => $image,
          'excerpt'       => (string) ($ci['excerpt'] ?? $ci['summary'] ?? ''),
          'percent'       => intval($ci['percent'] ?? $ci['progress_percent'] ?? 0),
          'modules_count' => intval($ci['modules_count'] ?? 0),
          'lessons_count' => intval($ci['lessons_count'] ?? 0),
          'access'        => 'owned',
          'is_owned'      => true,
        ];
        $owned_ids[] = $id;
      }

      $out = [
        'total'      => count($items),
        'owned'      => count($items),
        'itemsOwned' => array_values(array_unique($owned_ids)),
        'items'      => $items,
      ];

      return rest_ensure_response($out);
    },
  ]);
});

add_action('rest_api_init', function () {
  register_rest_route('bodhi-mobile/v1', '/course/(?P<id>\\d+)', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {

      $course_id = intval($req->get_param('id'));
      $user_id   = get_current_user_id();
      if (!$course_id || !$user_id) {
        return new WP_Error('bad_request', 'Faltan parámetros.', ['status' => 400]);
      }

      $r1 = rest_do_request(new WP_REST_Request('GET', "/bodhi/v1/courses/$course_id"));
      if (is_wp_error($r1)) {
        return $r1;
      }
      $c = $r1->get_data();

      $r2 = new WP_REST_Request('GET', '/bodhi/v1/progress');
      $r2->set_param('course_id', $course_id);
      $p = rest_do_request($r2);
      $progress = !is_wp_error($p) ? $p->get_data() : [];

      $pick = function ($a, $keys, $def = '') {
        foreach ($keys as $k) {
          if (isset($a[$k])) {
            return $a[$k];
          }
        }
        return $def;
      };

      $title = (string) $pick($c, ['title', 'name', 'post_title'], '');
      $image = (string) $pick($c, ['image', 'thumbnail', 'thumb', 'featured_image'], '');
      $excerpt = (string) $pick($c, ['excerpt', 'summary', 'description'], '');

      $modsSrc = [];
      foreach (['modules', 'sections', 'units'] as $k) {
        if (!empty($c[$k]) && is_array($c[$k])) {
          $modsSrc = $c[$k];
          break;
        }
      }

      $modules = [];
      $lessons_count = 0;

      foreach ($modsSrc as $i => $m) {
        $mlist = [];
        $ls = [];
        foreach (['lessons', 'items', 'children'] as $k) {
          if (!empty($m[$k]) && is_array($m[$k])) {
            $ls = $m[$k];
            break;
          }
        }
        foreach ($ls as $j => $L) {
          $lid = intval($pick($L, ['id', 'lesson_id', 'post_id']));
          $ltitle = (string) $pick($L, ['title', 'name', 'post_title'], "Lección $j");
          $dur = intval($pick($L, ['duration', 'duration_sec', 'seconds'], 0));

          $vimeo_id = (string) $pick($L, ['vimeo_id', 'vimeoId', 'video_id', 'videoid'], '');
          $player_url = (string) $pick($L, ['vimeo_player', 'player_url', 'video_url', 'url'], '');
          $thumb = (string) $pick($L, ['thumb', 'thumbnail', 'image'], '');

          $mlist[] = [
            'id'           => $lid,
            'title'        => $ltitle,
            'index'        => $j,
            'duration_sec' => $dur,
            'is_locked'    => false,
            'vimeo'        => [
              'id'         => $vimeo_id,
              'player_url' => $player_url,
              'thumb'      => $thumb,
            ],
          ];
          $lessons_count++;
        }

        $modules[] = [
          'id'      => intval($pick($m, ['id', 'module_id', 'post_id'], $i + 1)),
          'title'   => (string) $pick($m, ['title', 'name', 'post_title'], 'Módulo ' . ($i + 1)),
          'index'   => $i,
          'lessons' => $mlist,
        ];
      }

      $out = [
        'id'            => $course_id,
        'title'         => $title,
        'image'         => $image,
        'excerpt'       => $excerpt,
        'percent'       => intval($pick($progress, ['percent', 'progress_percent'], 0)),
        'modules_count' => count($modules),
        'lessons_count' => $lessons_count,
        'modules'       => $modules,
        'progress'      => [
          'percent'            => intval($pick($progress, ['percent', 'progress_percent'], 0)),
          'completed_lessons'  => (array) $pick($progress, ['completed_lessons', 'done', 'completed'], []),
        ],
      ];

      return rest_ensure_response($out);
    },
  ]);
});
add_action('rest_api_init', function () {
  register_rest_route('bm/v1', '/form-login', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $p = $req->get_json_params();
      $creds = [
        'user_login'    => $p['email'] ?? '',
        'user_password' => $p['password'] ?? '',
        'remember'      => true,
      ];
      $user = wp_signon($creds, false);
      if (is_wp_error($user)) {
        return new WP_Error('auth_failed', 'Credenciales inválidas', ['status' => 403]);
      }
      wp_set_current_user($user->ID);
      wp_set_auth_cookie($user->ID, true);
      $nonce = wp_create_nonce('wp_rest');
      return rest_ensure_response([
        'ok'    => true,
        'nonce' => $nonce,
        'user'  => [
          'id'    => (int) $user->ID,
          'email' => $user->user_email,
          'name'  => $user->display_name,
        ],
      ]);
    },
  ]);
});
