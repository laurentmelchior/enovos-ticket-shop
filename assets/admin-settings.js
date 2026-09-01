(function () {
  function syncProviderFields(select) {
    if (!select) return;
    var provider = select.value;
    document.querySelectorAll('[data-enovos-provider]').forEach(function (block) {
      var match = block.getAttribute('data-enovos-provider') === provider;
      block.hidden = !match;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('enovos_ai_provider');
    if (select) {
      syncProviderFields(select);
      select.addEventListener('change', function () {
        syncProviderFields(select);
      });
    }

    document.querySelectorAll('.enovos-copy-token').forEach(function (button) {
      button.addEventListener('click', function () {
        var token = button.getAttribute('data-token') || '';
        if (!token || !navigator.clipboard) return;
        navigator.clipboard.writeText(token).then(function () {
          button.classList.add('is-copied');
          window.setTimeout(function () {
            button.classList.remove('is-copied');
          }, 1200);
        });
      });
    });
  });
})();
