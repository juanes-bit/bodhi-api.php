<?php
if (!defined('ABSPATH')) { exit; }

// Carga segura de REST móvil
add_action('rest_api_init', function () {

  // /wp-json/bodhi-mobile/v1/me  → perfil rápido usando SOLO cookie
  register_rest_route('bodhi-mobile/v1', '/me', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in();
    },
    'callback' => function () {
      $u = wp_get_current_user();
      return rest_ensure_response([
        'id'    => (int) $u->ID,
        'name'  => $u->display_name,
        'email' => $u->user_email,
      ]);
    },
  ]);

  // /wp-json/bodhi-mobile/v1/my-courses  → proxy a tu lógica existente de cursos (union)
  register_rest_route('bodhi-mobile/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in(); // SOLO cookie, sin nonce
    },
    'callback' => function (WP_REST_Request $req) {

      // Llama internamente a tu endpoint ya existente /bodhi/v1/courses?mode=union
      $inner = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $inner->set_param('mode', 'union');
      $inner->set_param('per_page', max(1, (int)($req->get_param('per_page') ?: 12)));

      $resp = rest_do_request($inner);
      if ($resp instanceof WP_Error) {
        return $resp;
      }
      if (is_wp_error($resp)) {
        return $resp;
      }

      $data = ($resp instanceof WP_REST_Response) ? $resp->get_data() : $resp;
      $raw  = is_array($data) ? ($data['items'] ?? $data) : [];

      $items = [];
      foreach ($raw as $c) {
        $accessRaw = $c['access'] ?? ($c['access_status'] ?? '');
        $hasFlag   = [
          $c['is_owned'] ?? null,
          $c['isOwned'] ?? null,
          $c['owned'] ?? null,
          $c['access_granted'] ?? null,
          $c['user_has_access'] ?? null,
        ];
        $isOwned = in_array(strtolower((string) $accessRaw), ['owned','member','free','owned_by_product','access_granted','has_access'], true)
          || array_filter($hasFlag);

        $items[] = [
          'id'      => (int)($c['id'] ?? 0),
          'title'   => $c['course']['name'] ?? $c['title'] ?? '',
          'image'   => $c['course']['thumb'] ?? $c['course']['thumbnail'] ?? $c['course']['image'] ?? null,
          'percent' => isset($c['course']['percent']) ? (float) $c['course']['percent'] : 0,
          'access'  => $isOwned ? 'owned' : 'locked',
          'isOwned' => (bool) $isOwned,
        ];
      }

      $owned = array_values(array_filter($items, function ($i) {
        return !empty($i['isOwned']);
      }));

      return rest_ensure_response([
        'items'      => $items,
        'itemsOwned' => $owned,
        'total'      => count($items),
        'owned'      => count($owned),
      ]);
    },
  ]);

  // (opcional) /nonce solo informativo para debugging
  register_rest_route('bodhi-mobile/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      return rest_ensure_response(['nonce' => wp_create_nonce('wp_rest')]);
    },
  ]);

});
