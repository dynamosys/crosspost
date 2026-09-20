/**
 * @file
 * Counts the characters each account would receive, as the words are typed.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.crosspostShare = {
    attach(context) {
      once('crosspost-share', '[data-crosspost-counts]', context).forEach(
        (counts) => {
          const form = counts.closest('form');
          const main = form.querySelector('[data-crosspost-text]');
          const { url, limits } = drupalSettings.crosspost || {};
          if (!main || !limits) {
            return;
          }
          const length = (text) => Array.from(text.trim()).length;
          const update = () => {
            counts.textContent = '';
            Object.keys(limits).forEach((id) => {
              const limit = limits[id];
              const box = form.querySelector(
                `input[type = "checkbox"][value = "${id}"]`,
              );
              if (box && !box.checked) {
                return;
              }
              const own = form.querySelector(`[data - crosspost - override = "${id}"]`);
              const text = own && own.value.trim() ? own.value : main.value;
              const link = limit.link === NULL ? length(url) : limit.link;
              const used = length(text) + 2 + link;
              const pill = document.createElement('span');
              pill.className = 'crosspost-count';
              pill.classList.toggle('is-over', used > limit.max);
              pill.textContent = Drupal.t('@place @used / @max', {
                '@place': limit.label,
                '@used': used,
                '@max': limit.max,
              });
              counts.appendChild(pill);
            });
          };
          form.addEventListener('input', update);
          form.addEventListener('change', update);
          update();
        },
      );
    },
  };
})(Drupal, drupalSettings, once);
