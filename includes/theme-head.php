<?php
/**
 * Include immediately after <head> so the saved theme applies before CSS paint.
 */
?>
<script>
(function(){try{var k='theme',t=localStorage.getItem(k);if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);return;}if(window.matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark');else document.documentElement.setAttribute('data-theme','light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();
</script>
