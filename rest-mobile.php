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
    'permission_callback' => 'bodhi_rest_permission_cookie', // cookie-only
    'callback' => function (WP_REST_Request $req) {

      $user_id = get_current_user_id();
      if (!$user_id) {
        return new WP_Error('rest_forbidden', __('No estás conectado.'), ['status' => 401]);
      }

      // Reutiliza la lógica ya existente del endpoint "union" interno
      // para no duplicar reglas de negocio. Esto NO requiere nonce por ser interno.
      $inner = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $inner->set_param('mode', 'union');

      $resp = rest_do_request($inner);
      if (is_wp_error($resp)) {
        return $resp;
      }
      $data = $resp->get_data();

      $items = [];
      $owned_ids = [];

      // Helpers
      $boolish = function ($v) {
        if (is_bool($v)) return $v;
        $t = is_string($v) ? strtolower(trim($v)) : $v;
        return in_array($t, [1, '1', 'true', 'yes', 'y', 'owned'], true);
      };
      $normalize_id = function ($it) {
        return intval(
          $it['id'] ??
          $it['ID'] ??
          $it['course_id'] ??
          $it['wp_post_id'] ??
          $it['post_id'] ??
          $it['courseId'] ??
          0
        );
      };

      // Soportar data.items o data.data.items
      $src_items = [];
      if (is_array($data)) {
        if (!empty($data['items']) && is_array($data['items'])) {
          $src_items = $data['items'];
        } elseif (!empty($data['data']['items']) && is_array($data['data']['items'])) {
          $src_items = $data['data']['items'];
        }
      }

      foreach ($src_items as $node) {
        // Aplana wrapper tipo { course:{...}, owned_by_product:... }
        $raw = (is_array($node) && !empty($node['course']) && is_array($node['course'])) ? $node['course'] : $node;

        $id = $normalize_id($raw);
        if (!$id) continue;

        // Señales de propiedad (wrapper + item)
        $accessRaw = $raw['access'] ?? ($node['access'] ?? null);
        $owned =
          $boolish($raw['is_owned'] ?? null) ||
          $boolish($raw['owned'] ?? null) ||
          $boolish($raw['access_granted'] ?? null) ||
          $boolish($node['owned_by_product'] ?? null) ||
          $boolish($node['has_access'] ?? null) ||
          (is_string($accessRaw) && strtolower($accessRaw) === 'owned');

        if ($owned) { $owned_ids[] = $id; }

        // Salida normalizada (contrato móvil)
        $items[] = [
          'id'            => $id,
          'courseId'      => $id,
          'title'         => $raw['title'] ?? $raw['name'] ?? '',
          'image'         => $raw['image'] ?? $raw['thumbnail'] ?? '',
          'excerpt'       => $raw['excerpt'] ?? $raw['summary'] ?? '',
          'percent'       => intval($raw['percent'] ?? $raw['progress_percent'] ?? 0),
          'modules_count' => intval($raw['modules_count'] ?? 0),
          'lessons_count' => intval($raw['lessons_count'] ?? 0),
          'access'        => $owned ? 'owned' : 'locked',
          'is_owned'      => $owned,
        ];
      }

      // itemsOwned puede venir en varios sitios/nombres
      $owned_list = array_map('intval', array_filter([
        ...(is_array($data['itemsOwned'] ?? null) ? $data['itemsOwned'] : []),
        ...(is_array($data['ownedItems'] ?? null) ? $data['ownedItems'] : []),
        ...(is_array($data['owned_ids'] ?? null) ? $data['owned_ids'] : []),
        ...(is_array($data['data']['itemsOwned'] ?? null) ? $data['data']['itemsOwned'] : []),
        ...(is_array($data['data']['ownedItems'] ?? null) ? $data['data']['ownedItems'] : []),
      ]));
      if (!empty($owned_list)) {
        $owned_ids = array_merge($owned_ids, $owned_list);
      }

      // Consolidar propiedad final
      $owned_ids = array_values(array_unique(array_filter(array_map('intval', $owned_ids))));
      if (!empty($owned_ids)) {
        $owned_set = array_flip($owned_ids);
        foreach ($items as &$it) {
          if (isset($owned_set[$it['id']])) {
            $it['is_owned'] = true;
            $it['access'] = 'owned';
          }
        }
        unset($it);
      }

      $out = [
        'total'      => count($items),
        'owned'      => count(array_filter($items, fn($i) => !empty($i['is_owned']))),
        'itemsOwned' => $owned_ids,
        'items'      => $items,
      ];

      // Cache corto por usuario (escala: 6k+ estudiantes)
      set_transient('bodhi_mobile_my_courses_' . $user_id, $out, 60); // 60s para evitar thundering herd
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
