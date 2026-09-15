param(
  [string]$BaseUrl = 'https://telvora.ru'
)
$ErrorActionPreference = 'Stop'

function Check-Url([string]$Path, [int]$Expected, [string]$Location = '') {
  $uri = if ($Path -match '^https?://') { $Path } else { "$BaseUrl$Path" }
  $response = curl.exe -sS -o NUL -D - --max-time 20 --max-redirs 0 $uri
  $status = [regex]::Match(($response -join "`n"), 'HTTP/[^ ]+ ([0-9]{3})').Groups[1].Value
  if ($status -ne "$Expected") { throw "$Path expected $Expected, got $status" }
  if ($Location) {
    $actual = [regex]::Match(($response -join "`n"), '(?im)^Location:\s*(.+)$').Groups[1].Value.Trim()
    if ($actual -ne $Location) { throw "$Path expected Location $Location, got $actual" }
  }
  Write-Output "PASS $Expected $Path"
}

# Canonical redirects, including query preservation.
Check-Url 'http://telvora.ru/' 301 'https://telvora.ru/'
Check-Url 'http://www.telvora.ru/catalog/oled?foo=bar' 301 'https://telvora.ru/catalog/oled?foo=bar'
Check-Url 'https://www.telvora.ru/catalog/oled?foo=bar' 301 'https://telvora.ru/catalog/oled?foo=bar'

# Known prerender routes.
foreach ($path in @('/', '/catalog', '/catalog/oled', '/catalog/qled', '/catalog/led', '/catalog/8k', '/delivery', '/services', '/warranty', '/returns', '/support', '/contacts', '/requisites', '/offer', '/privacy', '/personal-data-consent', '/cookies', '/catalog/oled/lg-oled77c5rla')) {
  Check-Url $path 200
}

# Client-only routes remain direct-loadable but noindex.
foreach ($path in @('/checkout', '/admin', '/order-success/test')) { Check-Url $path 200 }

# Unknown routes must be a real HTTP 404.
foreach ($path in @('/definitely-not-existing-telvora-seo-test', '/catalog/not-a-category', '/catalog/oled/not-active')) { Check-Url $path 404 }

# Read-only application/static checks; use only GET/HEAD endpoints with no order mutation.
foreach ($path in @('/products.php?action=list', '/services.php', '/robots.txt', '/sitemap.xml')) { Check-Url $path 200 }
Write-Output 'SEO Stage 2 post-deploy matrix: PASS'
