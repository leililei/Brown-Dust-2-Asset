<?php

function decodeVarint(string $d, int &$o): int
{
    $r = 0;
    $s = 0;
    while ($o < strlen($d)) {
        $b = ord($d[$o++]);
        $r |= ($b & 0x7F) << $s;
        if (!($b & 0x80)) break;
        $s += 7;
    }
    return $r;
}

function decodeProto(string $d): array
{
    $o = 0;
    $r = [];
    while ($o < strlen($d)) {
        $tag = decodeVarint($d, $o);
        $fn = $tag >> 3;
        $wt = $tag & 7;
        if ($wt === 0) $v = decodeVarint($d, $o);
        elseif ($wt === 2) {
            $l = decodeVarint($d, $o);
            $v = substr($d, $o, $l);
            $o += $l;
        } elseif ($wt === 5) {
            $v = unpack('V', substr($d, $o, 4))[1];
            $o += 4;
        } else break;
        $r[(string)$fn] = $v;
    }
    return $r;
}

function encodeProto(array $msg, array $def): string
{
    $r = '';
    foreach ($msg as $f => $v) {
        $fn = (int)$f;
        if ($def[$f]['type'] === 'int') {
            $r .= chr(($fn << 3) | 0);
            $t = $v;
            while ($t > 0x7F) {
                $r .= chr(($t & 0x7F) | 0x80);
                $t >>= 7;
            }
            $r .= chr($t);
        } else {
            $r .= chr(($fn << 3) | 2);
            $l = strlen($v);
            while ($l > 0x7F) {
                $r .= chr(($l & 0x7F) | 0x80);
                $l >>= 7;
            }
            $r .= chr($l) . $v;
        }
    }
    return $r;
}

ini_set('memory_limit', '1024M');

function getCdnVersion(): string
{
    $def = ["1" => ["type" => "int"], "2" => ["type" => "int"], "3" => ["type" => "bytes"]];
    $msg = ["1" => 2, "2" => 8, "3" => "1.68.11"];

    $ch = curl_init("https://mt.bd2.pmang.cloud/MaintenanceInfo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => base64_encode(encodeProto($msg, $def)),
    ]);
    $r = curl_exec($ch);
    curl_close($ch);

    $inner = decodeProto(decodeProto(base64_decode(json_decode($r, true)['data']))["1"]);
    // field 2 = game version (2.27.19), field 3 = CDN version (20260602132343)
    return $inner["3"];
}

// 获取版本号
$version = getCdnVersion();
echo "获取到的版本号: $version\n";

$platform   = 'StandaloneWindows64';
$resolution = 'HD';
$baseUrl    = "https://cdn.bd2.pmang.cloud/ServerData/{$platform}/{$resolution}/{$version}";

// ── 下载 catalog 到内存 ─────────────────────────────────────────────
$catalogUrl = $baseUrl . '/catalog_alpha.json';
echo "下载 catalog: $catalogUrl\n";

$ch = curl_init($catalogUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$catalogRaw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$catalogRaw) {
    die("catalog 下载失败，HTTP {$httpCode}\n");
}
echo "catalog 已加载到内存 (" . strlen($catalogRaw) . " bytes)\n";

// ── 解析 catalog，提取所有 .bundle 路径 ─────────────────────────────
$catalog = json_decode($catalogRaw, true);
if (!$catalog) {
    die("catalog JSON 解析失败\n");
}

$bundlePaths = [];
extractBundles($catalog, $bundlePaths);
$bundlePaths = array_unique($bundlePaths);
echo "共找到 " . count($bundlePaths) . " 个 bundle 条目\n";

// 只保留包含版本号的平台资产
$assetPaths = array_filter($bundlePaths, function ($p) use ($version) {
    return strpos($p, $version) !== false || strpos($p, 'StandaloneWindows64') !== false;
});
$assetPaths = array_values($assetPaths);
echo "平台相关 bundle: " . count($assetPaths) . " 个\n";


writeAria2File('aria2_all.txt', $assetPaths, $baseUrl);


// ── 辅助函数 ────────────────────────────────────────────────────────

function extractBundles(array $obj, array &$out): void
{
    foreach ($obj as $v) {
        if (is_string($v)) {
            if (stripos($v, '.bundle') !== false) {
                $out[] = $v;
            }
        } elseif (is_array($v)) {
            extractBundles($v, $out);
        }
    }
}

function pathToUrl(string $assetPath, string $baseUrl): string
{
    $filename = basename(str_replace('\\', '/', $assetPath));
    return "{$baseUrl}/{$filename}";
}

function writeAria2File(string $filepath, array $assets, string $baseUrl): void
{
    $fp = fopen($filepath, 'w');
    foreach ($assets as $asset) {
        $url      = pathToUrl($asset, $baseUrl);
        $filename = basename(str_replace('\\', '/', $asset));
        fwrite($fp, $url . "\n");
        fwrite($fp, "  out={$filename}\n");
        fwrite($fp, "\n");
    }
    fclose($fp);
    echo "  {$filepath} (" . count($assets) . " 条)\n";
}
