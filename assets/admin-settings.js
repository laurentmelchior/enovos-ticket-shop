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
    if (!select) return;
    syncProviderFields(select);
    select.addEventListener('change', function () {
      syncProviderFields(select);
    });
  });
})();
