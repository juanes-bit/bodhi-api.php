<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Endpoints mínimos para la app móvil:
 *   GET  /wp-json/bm/v1/rest-nonce  -> { nonce }
 *   POST /wp-json/bm/v1/form-login  -> { ok, user, nonce, token:null }
 */

add_action('rest_api_init', function () {
  // POST /bm/v1/form-login
  register_rest_route('bm/v1', '/form-login', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (\WP_REST_Request $req) {
      $p      = $req->get_json_params();
      $email  = isset($p['email']) ? sanitize_email($p['email']) : '';
      $pass   = isset($p['password']) ? (string)$p['password'] : '';

      if (!$email || !$pass) {
        return new \WP_Error('bad_request', 'Faltan credenciales', ['status' => 400]);
      }

      $user = get_user_by('email', $email);
      if (!$user) {
        return new \WP_Error('invalid_email', 'Email no encontrado', ['status' => 401]);
      }

      $signed = wp_authenticate($user->user_login, $pass);
      if (is_wp_error($signed)) {
        return new \WP_Error('invalid_creds', 'Credenciales inválidas', ['status' => 401]);
      }

      $cookie_meta = bodhi_emit_login_cookies($signed, true);
      if (is_wp_error($cookie_meta)) {
        return $cookie_meta;
      }

      // Si tu front necesita CORS con credenciales desde web, ajusta el origin específico (no "*")
      // header('Access-Control-Allow-Origin: https://staging.bodhimedicine.com');
      // header('Access-Control-Allow-Credentials: true');

      return [
        'ok'    => true,
        'user'  => [
          'id'    => $signed->ID,
          'name'  => $signed->display_name,
          'roles' => $signed->roles,
        ],
        // Este nonce es válido en ESTA request; el cliente debe pedir uno fresco luego.
        'nonce' => wp_create_nonce('wp_rest'),
        'token' => null,
        'session' => $cookie_meta,
      ];
    },
  ]);

  // GET /bm/v1/rest-nonce
  register_rest_route('bm/v1', '/rest-nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      $uid = wp_validate_auth_cookie('', 'logged_in');
      if ($uid) {
        wp_set_current_user($uid);
        return true;
      }
      return new \WP_Error('not_logged_in', 'No estás conectado.', ['status' => 401]);
    },
    'callback' => function () {
      nocache_headers();
      return [
        'ok'    => true,
        'nonce' => wp_create_nonce('wp_rest'),
        'user'  => ['id' => get_current_user_id()],
      ];
    },
  ]);
});

// CORS básico para la app
add_action('rest_api_init', function () {
  remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
  add_filter('rest_pre_serve_request', function ($value) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Referer');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    return $value;
  });
}, 15);
