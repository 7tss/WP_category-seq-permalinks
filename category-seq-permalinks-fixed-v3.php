<?php
/**
 * Plugin Name: Category Sequential Permalinks (Fixed Version v3)
 * Description: カテゴリ別連番で /category-slug/番号/ を実現。主要カテゴリは独自メタで指定可。手動連番編集＆旧URL→新URLの301対応。404/410エラー対応、動的リライトルール、削除時クリーンアップ機能付き。
 * Version: 1.5.0
 * Author: ShareMemori
 * License: GPL v2 or later
 */

/** ========= 設定 ========= */
// 対象ポストタイプ：必要に応じて追加（カテゴリタクソノミーが紐づいている必要あり）
$csp_target_post_types  = ['post'];

// 対象カテゴリの限定（空配列=全カテゴリ対象）
$csp_allowed_categories = []; // 例: ['news','column']

// スラッグ置換（任意）：カテゴリの実スラッグ→URL上の別名
// 例：'news' を URL では 'topics' として見せる
$csp_slug_override = [
  // 'news' => 'topics',
];

$csp_meta_primary_cat = '_csp_primary_cat'; // 主要カテゴリを保存する独自メタ
$csp_meta_seq_prefix  = 'cat_seq_';         // 連番メタ：cat_seq_{term_id}

/** ========= ユーティリティ ========= */
function csp_is_target_post_type($pt){ global $csp_target_post_types;  return in_array($pt, $csp_target_post_types, true); }
function csp_is_allowed_category($term){
  global $csp_allowed_categories;
  if (!$term) return false;
  if (empty($csp_allowed_categories)) return true;
  return in_array($term->slug, $csp_allowed_categories, true);
}
function csp_meta_key_for_term($term_id){ global $csp_meta_seq_prefix; return $csp_meta_seq_prefix . intval($term_id); }

function csp_slug_for_term($term){
  global $csp_slug_override;
  return $csp_slug_override[$term->slug] ?? $term->slug;
}
function csp_resolve_slug_to_category($slug){
  $term = get_category_by_slug(sanitize_title($slug));
  if ($term && !is_wp_error($term)) return $term;
  // 置換スラッグ対応（逆引き）
  global $csp_slug_override;
  if (!empty($csp_slug_override)) {
    foreach ($csp_slug_override as $orig => $alias) {
      if (sanitize_title($alias) === sanitize_title($slug)) {
        $t = get_category_by_slug($orig);
        if ($t && !is_wp_error($t)) return $t;
      }
    }
  }
  return null;
}

/** 主要カテゴリの決定（独自メタ > 最小term_id） */
function csp_get_primary_category($post_id){
  global $csp_meta_primary_cat;
  $saved = intval(get_post_meta($post_id, $csp_meta_primary_cat, true));
  if ($saved) {
    $t = get_term($saved, 'category');
    if ($t && !is_wp_error($t)) return $t;
  }
  $cats = get_the_terms($post_id, 'category');
  if (empty($cats) || is_wp_error($cats)) return null;
  usort($cats, fn($a,$b)=> $a->term_id <=> $b->term_id);
  return $cats[0];
}

/** 次の空き番号（最大+1） */
function csp_next_sequence_for_term($term_id){
  $meta_key = csp_meta_key_for_term($term_id);
  $q = new WP_Query([
    'post_type'      => 'any',
    'posts_per_page' => 1,
    'tax_query'      => [[ 'taxonomy'=>'category','field'=>'term_id','terms'=>[$term_id], ]],
    'meta_key'       => $meta_key,
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
    'no_found_rows'  => true,
    'fields'         => 'ids',
  ]);
  if ($q->have_posts()) {
    $max_id  = $q->posts[0];
    $max_val = intval(get_post_meta($max_id, $meta_key, true));
    return max(1, $max_val + 1);
  }
  return 1;
}

/** 希望番号が埋まっていたら重複しない値まで繰り上げ */
function csp_find_available_seq($term_id, $preferred, $exclude_post_id = 0){
  $meta_key = csp_meta_key_for_term($term_id);
  $n = max(1, intval($preferred));
  while (true) {
    $conflict = get_posts([
      'post_type'      => 'any',
      'posts_per_page' => 1,
      'tax_query'      => [[ 'taxonomy'=>'category','field'=>'term_id','terms'=>[$term_id], ]],
      'meta_key'       => $meta_key,
      'meta_value'     => $n,
      'fields'         => 'ids',
    ]);
    if (!$conflict || (count($conflict) === 1 && intval($conflict[0]) === intval($exclude_post_id))) return $n;
    $n++;
  }
}

/** ========= ルーティング ========= */
add_filter('query_vars', function($vars){ $vars[] = 'cat_seq'; return $vars; });

add_action('init', function(){
  // 許可カテゴリに限定した動的リライトルールを生成
  global $csp_allowed_categories, $csp_slug_override;
  
  // 許可カテゴリのスラッグリストを生成
  $allowed_slugs = [];
  
  if (empty($csp_allowed_categories)) {
    // 全カテゴリが対象の場合、実際に存在するカテゴリを取得
    $categories = get_categories(['hide_empty' => false]);
    foreach ($categories as $cat) {
      $allowed_slugs[] = $cat->slug;
      // スラッグ置換も含める
      if (isset($csp_slug_override[$cat->slug])) {
        $allowed_slugs[] = $csp_slug_override[$cat->slug];
      }
    }
  } else {
    // 限定カテゴリが指定されている場合
    foreach ($csp_allowed_categories as $slug) {
      $allowed_slugs[] = $slug;
      // スラッグ置換も含める
      if (isset($csp_slug_override[$slug])) {
        $allowed_slugs[] = $csp_slug_override[$slug];
      }
    }
  }
  
  // 正規表現用にエスケープ
  $escaped_slugs = array_map('preg_quote', $allowed_slugs);
  $pattern = '^(' . implode('|', $escaped_slugs) . ')/([0-9]+)/?$';
  
  // 動的にリライトルールを追加
  add_rewrite_rule($pattern, 'index.php?category_name=$matches[1]&cat_seq=$matches[2]', 'bottom');
});

/** /カテゴリ/番号/ → 投稿へ解決 */
add_filter('request', function($vars){
  if (isset($vars['cat_seq']) && isset($vars['category_name'])) {
    $cat = csp_resolve_slug_to_category($vars['category_name']);
    if ($cat) {
      $posts = get_posts([
        'post_type'      => 'any',
        'posts_per_page' => 1,
        'tax_query'      => [[ 'taxonomy'=>'category','field'=>'term_id','terms'=>[$cat->term_id], ]],
        'meta_key'       => csp_meta_key_for_term($cat->term_id),
        'meta_value'     => intval($vars['cat_seq']),
      ]);
      if ($posts) return ['p' => $posts[0]->ID];
    }
    // カテゴリが存在しない場合は、404を返すようにする
    return ['error' => '404'];
  }
  return $vars;
});

/** 期待される新URL（/カテゴリ/連番/） */
function csp_expected_permalink($post){
  if (is_numeric($post)) $post = get_post($post);
  if (!$post || !csp_is_target_post_type($post->post_type)) return false;

  $primary = csp_get_primary_category($post->ID);
  if (!$primary || !csp_is_allowed_category($primary)) return false;

  $seq = get_post_meta($post->ID, csp_meta_key_for_term($primary->term_id), true);
  if (!$seq) return false;

  $slug = csp_slug_for_term($primary);
  $path = trailingslashit($slug . '/' . intval($seq));
  return home_url(user_trailingslashit($path));
}

/** パーマリンク差し替え（投稿 & CPT） */
add_filter('post_link', function($permalink, $post){ return csp_expected_permalink($post) ?: $permalink; }, 10, 2);
add_filter('post_type_link', function($permalink, $post){ return csp_expected_permalink($post) ?: $permalink; }, 10, 2);

/** 旧URL → 新URLへ 301 */
add_action('template_redirect', function(){
  if (is_admin() || is_feed() || is_preview() || defined('REST_REQUEST') || (defined('DOING_AJAX') && DOING_AJAX)) return;
  if (!is_singular()) return;

  $post = get_queried_object();
  if (!$post || !csp_is_target_post_type($post->post_type)) return;

  $expected = csp_expected_permalink($post);
  if (!$expected) return;

  global $wp;
  $current = home_url(add_query_arg([], $wp->request));
  $normalize = function($url){
    $u = remove_query_arg(array_keys($_GET), $url);
    return trailingslashit(strtolower($u));
  };
  if ($normalize($current) !== $normalize($expected)) {
    if (!empty($_GET)) $expected = add_query_arg($_GET, $expected);
    wp_redirect($expected, 301);
    exit;
  }
});

/** 削除時の処理：関連する連番メタをクリーンアップ */
add_action('delete_post', function($post_id){
  global $csp_meta_seq_prefix;
  
  // 削除された投稿の連番メタを取得
  $meta_keys = get_post_custom_keys($post_id);
  if ($meta_keys) {
    foreach ($meta_keys as $key) {
      if (strpos($key, $csp_meta_seq_prefix) === 0) {
        delete_post_meta($post_id, $key);
      }
    }
  }
});

/** カテゴリ削除時の処理：関連する連番メタをクリーンアップ */
add_action('delete_category', function($term_id){
  global $csp_meta_seq_prefix;
  $meta_key = $csp_meta_seq_prefix . intval($term_id);
  
  // このカテゴリに関連する連番メタを持つ投稿を検索して削除
  $posts = get_posts([
    'post_type' => 'any',
    'posts_per_page' => -1,
    'meta_key' => $meta_key,
    'fields' => 'ids',
  ]);
  
  foreach ($posts as $post_id) {
    delete_post_meta($post_id, $meta_key);
  }
});

/** 保存時：主要カテゴリと連番 */
add_action('save_post', function($post_id, $post, $update){
  if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
  if (!csp_is_target_post_type($post->post_type)) return;

  // メタボックス入力の保存
  if (isset($_POST['csp_meta_nonce']) && wp_verify_nonce($_POST['csp_meta_nonce'], 'csp_meta_box')) {
    // 主要カテゴリ
    if (isset($_POST['csp_primary_cat'])) {
      $term_id = intval($_POST['csp_primary_cat']);
      $cats = get_the_terms($post_id, 'category');
      $ids  = $cats && !is_wp_error($cats) ? wp_list_pluck($cats, 'term_id') : [];
      if ($term_id && in_array($term_id, $ids, true)) update_post_meta($post_id, '_csp_primary_cat', $term_id);
    }
    // 手動連番
    if (isset($_POST['csp_manual_seq'])) {
      $primary = csp_get_primary_category($post_id);
      if ($primary && csp_is_allowed_category($primary)) {
        $raw = trim($_POST['csp_manual_seq']);
        if ($raw !== '') {
          $seq = csp_find_available_seq($primary->term_id, intval($raw), $post_id);
          update_post_meta($post_id, csp_meta_key_for_term($primary->term_id), $seq);
        }
      }
    }
  }

  // 自動採番（未設定時）
  $primary = csp_get_primary_category($post_id);
  if (!$primary || !csp_is_allowed_category($primary)) return;
  $meta_key = csp_meta_key_for_term($primary->term_id);
  $current  = get_post_meta($post_id, $meta_key, true);
  if ($current === '' || $current === null) {
    update_post_meta($post_id, $meta_key, csp_next_sequence_for_term($primary->term_id));
  }
}, 10, 3);

/** 管理画面メタボックス（主要カテゴリ & 連番手動） */
add_action('add_meta_boxes', function(){
  foreach (get_post_types(['public'=>true], 'names') as $pt) {
    if (csp_is_target_post_type($pt)) {
      add_meta_box('csp_meta', 'カテゴリ別連番（主要カテゴリ/手動連番）', function($post){
        wp_nonce_field('csp_meta_box', 'csp_meta_nonce');
        $cats = get_the_terms($post->ID, 'category');
        if (empty($cats) || is_wp_error($cats)) { echo 'カテゴリ未設定'; return; }
        $primary = csp_get_primary_category($post->ID);
        $primary_id = $primary ? $primary->term_id : 0;

        echo '<div style="font-size:12px;line-height:1.6">';
        echo '<label><strong>主要カテゴリ</strong></label><br />';
        echo '<select name="csp_primary_cat" style="width:100%">';
        foreach ($cats as $c) {
          printf('<option value="%d"%s>%s (%s)</option>', $c->term_id, selected($primary_id, $c->term_id, false), esc_html($c->name), esc_html($c->slug));
        }
        echo '</select>';
        echo '<p style="margin:6px 0 10px 0;">※ここで選んだカテゴリを基準に「/カテゴリ/連番/」を作ります。</p>';

        if ($primary) {
          $seq = get_post_meta($post->ID, csp_meta_key_for_term($primary->term_id), true);
          echo '<label><strong>連番（手動）</strong></label><br />';
          printf('<input type="number" min="1" step="1" name="csp_manual_seq" value="%s" style="width:100%%" />', esc_attr($seq));
          echo '<p style="margin:6px 0 0 0;">※重複時は自動で次の空き番号にスライドします。</p>';
        } else {
          echo '<p>主要カテゴリ未確定のため、連番編集は対象外です。</p>';
        }
        echo '</div>';
      }, $pt, 'side', 'default');
    }
  }
});

/** 初回だけリライトルールをフラッシュ */
add_action('admin_init', function(){
  $key = 'csp_rewrite_flushed_150';
  if (!get_option($key)) { flush_rewrite_rules(false); update_option($key, 1); }
});

/** 404エラーのログ記録（デバッグ用） */
add_action('wp', function(){
  if (is_404()) {
    global $wp;
    $requested_url = home_url($wp->request);
    error_log("CSP 404 Error: " . $requested_url);
  }
});

/** 不要なURLパターンに410 Goneを返す処理 */
add_action('template_redirect', function(){
  global $wp;
  
  $request_uri = $wp->request;
  
  // 410 Gone を返すべきURLパターン
  $gone_patterns = [
    // タグページの2階層以上
    '/^tag\/[^\/]+\/.+$/' => 'タグページの2階層以上は永続的に削除されました',
    
    // カテゴリページの2階層以上
    '/^category\/[^\/]+\/.+$/' => 'カテゴリページの2階層以上は永続的に削除されました',
    
    // カスタム投稿タイプの2階層以上（特定の組み合わせ）
    '/^(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/.+$/' => 'カスタム投稿タイプの2階層以上は永続的に削除されました',
    
    // ページ/カスタム投稿タイプの2階層以上
    '/^(about-us|contact|privacy-policy|privacy-policy-2)\/(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/.+$/' => 'ページ/カスタム投稿タイプの2階層以上は永続的に削除されました',
  ];
  
  foreach ($gone_patterns as $pattern => $message) {
    if (preg_match($pattern, $request_uri)) {
      // 410 Gone を返す
      status_header(410);
      header('Content-Type: text/html; charset=UTF-8');
      echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body><h1>410 Gone</h1><p>' . esc_html($message) . '</p></body></html>';
      exit;
    }
  }
});

/** 存在しないカテゴリURLのリダイレクト処理 */
add_action('template_redirect', function(){
  global $wp;
  
  // 404ページでない場合は処理しない
  if (!is_404()) return;
  
  $request_uri = $wp->request;
  
  // カテゴリ別連番パターンにマッチするかチェック
  if (preg_match('/^([a-zA-Z0-9\-_]+)\/([0-9]+)\/?$/', $request_uri, $matches)) {
    $category_slug = $matches[1];
    $sequence = $matches[2];
    
    // カテゴリが存在しない場合はホームページにリダイレクト
    $category = get_category_by_slug($category_slug);
    if (!$category || is_wp_error($category)) {
      wp_redirect(home_url('/'), 301);
      exit;
    }
  }
  
  // その他の404エラーURLパターンの処理
  $redirect_patterns = [
    // タグページ
    '/^tag\/[^\/]+\/.*$/' => home_url('/'),
    
    // カテゴリページ（category-プレフィックス）
    '/^category-[^\/]+\/[0-9]+\/.*$/' => home_url('/'),
    
    // カスタム投稿タイプのアーカイブ
    '/^(product-reviews|stories|first-car-camp)\/?$/' => home_url('/'),
    '/^(product-reviews|stories|first-car-camp)\/.*$/' => home_url('/'),
    
    // 検索ページ
    '/^search\/.*$/' => home_url('/'),
    
    // フィードページ
    '/^.*\/feed\/?$/' => home_url('/'),
    
    // ページ/カスタム投稿タイプの組み合わせ
    '/^(about-us|contact|privacy-policy|privacy-policy-2)\/(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/?$/' => home_url('/'),
    
    // カスタム投稿タイプ同士の組み合わせ
    '/^(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/?$/' => home_url('/'),
    
    // カテゴリ/カスタム投稿タイプの組み合わせ
    '/^category\/category-[^\/]+\/(product-reviews|stories|first-car-camp|how-to-tips|car-camping-gear|spot)\/?$/' => home_url('/'),
    
    // その他の特殊ページ
    '/^(sugoroku-tours|trending|act-on-specified-commercial-transactions)\/.*$/' => home_url('/'),
    '/^(sugoroku-tours|trending|act-on-specified-commercial-transactions)\/?$/' => home_url('/'),
    
    // 長いページ名
    '/^[a-zA-Z0-9\-_]{20,}\/.*$/' => home_url('/'),
  ];
  
  foreach ($redirect_patterns as $pattern => $redirect_url) {
    if (preg_match($pattern, $request_uri)) {
      wp_redirect($redirect_url, 301);
      exit;
    }
  }
});

/** リダイレクトエラーの処理 */
add_action('template_redirect', function(){
  global $wp;
  
  $request_uri = $wp->request;
  
  // リダイレクトが必要なURLパターン
  $redirect_rules = [
    // HTTPからHTTPSへのリダイレクト
    '/^http:\/\/www\.sharememori\.jp\/?$/' => 'https://www.sharememori.jp/',
    '/^http:\/\/sharememori\.jp\/?$/' => 'https://www.sharememori.jp/',
    '/^https:\/\/sharememori\.jp\/?$/' => 'https://www.sharememori.jp/',
    
    // 末尾スラッシュの統一
    '/^([^\/]+)\/?$/' => function($matches) {
      $path = $matches[1];
      // 既存のページや投稿でない場合のみリダイレクト
      if (!get_page_by_path($path) && !get_post_by_path($path)) {
        return home_url('/');
      }
      return null;
    },
    
    // カテゴリ別連番のリダイレクト
    '/^category-([^\/]+)\/([0-9]+)\/([^\/]+)\/?$/' => function($matches) {
      $category_slug = $matches[1];
      $sequence = $matches[2];
      $suffix = $matches[3];
      
      // カテゴリが存在する場合のみ処理
      $category = get_category_by_slug($category_slug);
      if ($category && !is_wp_error($category)) {
        // 適切なカテゴリページにリダイレクト
        return get_category_link($category->term_id);
      }
      return home_url('/');
    },
  ];
  
  foreach ($redirect_rules as $pattern => $redirect_url) {
    if (is_callable($redirect_url)) {
      if (preg_match($pattern, $request_uri, $matches)) {
        $result = $redirect_url($matches);
        if ($result) {
          wp_redirect($result, 301);
          exit;
        }
      }
    } else {
      if (preg_match($pattern, $request_uri)) {
        wp_redirect($redirect_url, 301);
        exit;
      }
    }
  }
});
