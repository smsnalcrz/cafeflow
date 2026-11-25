<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="theme-color" content="#162318">
  <script>
    (function() {
      try {
        var s = localStorage.getItem('cafeflow_theme');
        var t = s || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
        if (t === 'dark') document.documentElement.classList.add('dark-mode');
      } catch (e) {}
    })();
  </script>
  <title>CafeFlow · DK Cafe</title>
  <?php foreach ($cssModules as $css): ?>
  <link rel="stylesheet" href="<?= $css ?>?v=<?= $ver($css) ?>">
  <?php endforeach; ?>
</head>
<body>
