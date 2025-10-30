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

      bodhi_validate_cookie_user();
      $_GET['mode'] = 'union';
      $page = max(1, (int) ($req->get_param('page') ?: 1));
      $per_page = max(1, min(50, (int) ($req->get_param('per_page') ?: 24)));
      $owned_param = $req->get_param('owned');
      $owned_only = is_null($owned_param) ? true : (bool) filter_var($owned_param, FILTER_VALIDATE_BOOLEAN);
      $debug = (bool) filter_var($req->get_param('debug'), FILTER_VALIDATE_BOOLEAN);

      $diag = null;
      $payload = bodhi_prepare_courses_payload($page, $per_page, $owned_only, 'union', $debug, $diag);
      if (is_wp_error($payload)) {
        if ($debug && $diag !== null && is_array($diag)) {
          $payload->add_data(['__debug' => $diag]);
        }
        return $payload;
      }

      $response = bodhi_emit_courses($payload['items'], $payload['owned_ids']);
      if ($debug && is_array($diag)) {
        $data = $response->get_data();
        $data['__debug'] = $diag;
        $response->set_data($data);
      }
      return $response;
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
