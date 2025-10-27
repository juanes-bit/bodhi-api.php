<?php
if (!defined('ABSPATH')) { exit; }

// Carga segura de REST móvil
add_action('rest_api_init', function () {

  // /wp-json/bodhi-mobile/v1/me  → perfil rápido usando SOLO cookie
  register_rest_route('bodhi-mobile/v1', '/me', [
    'methods'  => 'GET',
    'permission_callback' => 'bodhi_rest_permission_cookie',
    'callback' => function () {
      $uid = bodhi_validate_cookie_user();
      if ($uid <= 0) {
        return new WP_Error('rest_forbidden', __('Lo siento, no tienes permisos para hacer eso.'), ['status' => 401]);
      }
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
    'permission_callback' => 'bodhi_rest_permission_cookie',
    'callback' => function (WP_REST_Request $req) {

      // Proxy a /bodhi/v1/courses (modo union) para obtener la misma data base.
      bodhi_validate_cookie_user();
      $inner = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $inner->set_param('mode', 'union');
      $inner->set_param('per_page', max(1, (int)($req->get_param('per_page') ?: 24)));

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
      $ownedIds = [];

      foreach ($raw as $c) {
        $access = strtolower(trim((string) ($c['access'] ?? '')));

        $reasonsRaw = $c['access_reason'] ?? [];
        if (!is_array($reasonsRaw)) {
          $reasonsRaw = array_filter(array_map('trim', explode(',', (string) $reasonsRaw)));
        }
        $reasons = array_map('strtolower', $reasonsRaw);

        $flags = [
          $c['is_owned'] ?? null,
          $c['isOwned'] ?? null,
          $c['owned'] ?? null,
          $c['owned_by_product'] ?? null,
          $c['has_access'] ?? null,
          $c['user_has_access'] ?? null,
          $c['access_granted'] ?? null,
          $c['member'] ?? null,
        ];

        $anyFlag = false;
        foreach ($flags as $f) {
          if ($f === true || $f === 1) {
            $anyFlag = true;
            break;
          }
          if (is_string($f) && in_array(strtolower($f), ['1', 'true', 'yes'], true)) {
            $anyFlag = true;
            break;
          }
        }

        $accessOk = in_array($access, ['owned', 'member', 'free', 'granted', 'has_access', 'owned_by_product'], true);
        $reasonOk = count(array_intersect($reasons, ['owned_by_product', 'has_access', 'granted', 'enrolled'])) > 0;
        $isOwned  = $accessOk || $reasonOk || $anyFlag;

        $itemId = (int) ($c['id'] ?? 0);

        $items[] = [
          'id'      => $itemId,
          'title'   => $c['course']['name'] ?? $c['title'] ?? '',
          'image'   => $c['course']['thumb'] ?? $c['course']['thumbnail'] ?? $c['course']['image'] ?? null,
          'percent' => isset($c['course']['percent']) ? (float) $c['course']['percent'] : 0,
          'access'  => $isOwned ? 'owned' : 'locked',
          'is_owned'=> (bool) $isOwned,
        ];

        if ($isOwned && $itemId > 0) {
          $ownedIds[] = $itemId;
        }
      }

      $ownedIds = array_values(array_unique($ownedIds));

      return rest_ensure_response([
        'items'    => $items,
        'ownedIds' => $ownedIds,
        'total'    => count($items),
        'owned'    => count($ownedIds),
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
