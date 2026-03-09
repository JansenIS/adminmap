<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';

function orders_rss_xml_escape(string $value): string {
  return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function orders_rss_html_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function orders_rss_to_absolute_url(string $url, string $scheme, string $host): string {
  $url = trim($url);
  if ($url === '') return '';
  if (preg_match('~^https?://~i', $url)) return $url;
  if (str_starts_with($url, '//')) return $scheme . ':' . $url;
  if (!str_starts_with($url, '/')) $url = '/' . $url;
  return $scheme . '://' . $host . $url;
}

function orders_rss_pick_image_url(array $images, string $scheme, string $host): string {
  foreach ($images as $img) {
    $url = '';
    if (is_string($img)) {
      $url = $img;
    } elseif (is_array($img)) {
      $url = (string)($img['url'] ?? '');
      if ($url === '') {
        $meta = is_array($img['meta'] ?? null) ? $img['meta'] : [];
        $url = (string)($meta['url'] ?? '');
      }
    }
    $url = orders_rss_to_absolute_url($url, $scheme, $host);
    if ($url !== '') return $url;
  }
  return '';
}

orders_api_ensure_store();
$raw = @file_get_contents(orders_api_publications_path());
$dec = is_string($raw) ? json_decode($raw, true) : null;
$rows = is_array($dec['rows'] ?? null) ? $dec['rows'] : [];

$entity = trim((string)($_GET['entity_id'] ?? ''));
$turn = (int)($_GET['turn'] ?? 0);
$cat = trim((string)($_GET['category'] ?? ''));
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$baseLink = trim((string)($_GET['link'] ?? ''));

$items = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;
  if ($entity !== '' && (string)($r['entity_id'] ?? '') !== $entity) continue;
  if ($turn > 0 && (int)($r['turn_year'] ?? 0) !== $turn) continue;
  if ($cat !== '') {
    $cats = is_array($r['categories'] ?? null) ? $r['categories'] : [];
    if (!in_array($cat, $cats, true)) continue;
  }
  $items[] = $r;
}
$items = array_reverse($items);
$items = array_slice($items, 0, $limit);

$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$self = $scheme . '://' . $host . '/api/orders/rss/';
$feedLink = $baseLink !== '' ? $baseLink : $self;

$feedTitle = 'Летопись приказов';
if ($entity !== '') $feedTitle .= ' — ' . $entity;
if ($turn > 0) $feedTitle .= ' — ход ' . $turn;

$xml = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">';
$xml[] = '<channel>';
$xml[] = '<title>' . orders_rss_xml_escape($feedTitle) . '</title>';
$xml[] = '<link>' . orders_rss_xml_escape($feedLink) . '</link>';
$xml[] = '<description>' . orders_rss_xml_escape('Экспорт летописи приказов в формате RSS 2.0 для импорта во VK.') . '</description>';
$xml[] = '<language>ru</language>';
$xml[] = '<lastBuildDate>' . gmdate(DATE_RSS) . '</lastBuildDate>';

foreach ($items as $it) {
  $id = (string)($it['id'] ?? orders_api_next_id('feed'));
  $title = trim((string)($it['title'] ?? ''));
  $entityId = trim((string)($it['entity_id'] ?? ''));
  $turnYear = (int)($it['turn_year'] ?? 0);
  $rp = trim((string)($it['rp_post'] ?? ''));
  $verdict = trim((string)($it['public_verdict_text'] ?? ''));
  $created = trim((string)($it['created_at'] ?? ''));
  $ts = strtotime($created);
  if ($ts === false) $ts = time();

  $itemTitle = $title !== '' ? $title : ('Летопись хода ' . $turnYear);
  if ($entityId !== '') $itemTitle .= ' — ' . $entityId;

  $guid = $self . '?id=' . rawurlencode($id);
  $images = is_array($it['images'] ?? null) ? $it['images'] : [];
  $imageUrl = orders_rss_pick_image_url($images, $scheme, $host);

  $descParts = [];
  $descParts[] = '<h3>' . orders_rss_html_escape($itemTitle) . '</h3>';
  if ($rp !== '') $descParts[] = '<p><b>Пост:</b><br>' . nl2br(orders_rss_html_escape($rp)) . '</p>';
  if ($verdict !== '') $descParts[] = '<p><b>Вердикт:</b><br>' . nl2br(orders_rss_html_escape($verdict)) . '</p>';
  if ($imageUrl !== '') {
    $safeImg = orders_rss_html_escape($imageUrl);
    $descParts[] = '<p><img src="' . $safeImg . '" alt="Иллюстрация летописи"></p>';
  }
  $description = implode('', $descParts);
  if ($description === '') $description = '<p>Пустая запись летописи.</p>';

  $xml[] = '<item>';
  $xml[] = '<title>' . orders_rss_xml_escape($itemTitle) . '</title>';
  $xml[] = '<link>' . orders_rss_xml_escape($guid) . '</link>';
  $xml[] = '<guid isPermaLink="false">' . orders_rss_xml_escape($id) . '</guid>';
  $xml[] = '<pubDate>' . gmdate(DATE_RSS, $ts) . '</pubDate>';
  $xml[] = '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $description) . ']]></description>';
  if ($imageUrl !== '') {
    $xml[] = '<enclosure url="' . orders_rss_xml_escape($imageUrl) . '" type="image/jpeg"/>';
    $xml[] = '<media:content url="' . orders_rss_xml_escape($imageUrl) . '" medium="image"/>';
  }
  $xml[] = '</item>';
}

$xml[] = '</channel>';
$xml[] = '</rss>';

$body = implode("\n", $xml);
header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: no-store');
echo $body;
