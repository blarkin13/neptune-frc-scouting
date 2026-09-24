(() => {
  const root = document.querySelector('.dg');
  if (!root) return;
  const input = document.getElementById('dgSearch');
  const clear = document.getElementById('dgClear');
  const count = document.getElementById('dgResultCount');
  const empty = document.getElementById('dgEmpty');
  const topics = [...document.querySelectorAll('.dg-topic')];
  const links = [...document.querySelectorAll('[data-topic-link]')];
  const ui = window.NeptuneUI || { toast: () => {} };

  const normalize = value => String(value || '').trim().toLowerCase();

  function filter() {
    const q = normalize(input?.value);
    let shown = 0;
    topics.forEach(topic => {
      const match = !q || topic.dataset.search.includes(q);
      topic.classList.toggle('dg-filtered', !match);
      if (match) shown++;
    });
    if (count) count.textContent = `${shown} topic${shown === 1 ? '' : 's'}`;
    if (empty) empty.hidden = shown !== 0;
    links.forEach(link => {
      const topic = document.querySelector(`[data-topic="${CSS.escape(link.dataset.topicLink || '')}"]`);
      link.hidden = !!topic?.classList.contains('dg-filtered');
    });
  }

  input?.addEventListener('input', filter);
  clear?.addEventListener('click', () => {
    if (input) input.value = '';
    filter();
    input?.focus();
  });

  document.addEventListener('click', async event => {
    const tag = event.target.closest?.('[data-search-tag]');
    if (tag && input) {
      input.value = tag.dataset.searchTag || '';
      filter();
      input.focus();
      window.scrollTo({ top: root.offsetTop - 90, behavior: 'smooth' });
      return;
    }

    const copy = event.target.closest?.('[data-copy]');
    if (!copy) return;
    const text = copy.dataset.copy || '';
    try {
      await navigator.clipboard.writeText(text);
      copy.classList.add('dg-copy-good');
      window.setTimeout(() => copy.classList.remove('dg-copy-good'), 900);
      ui.toast?.(`Copied: ${text}`, 'good', { duration: 1800 });
    } catch (_) {
      ui.toast?.('Could not copy that search term.', 'bad');
    }
  });

  const observer = new IntersectionObserver(entries => {
    const visible = entries.filter(entry => entry.isIntersecting && !entry.target.classList.contains('dg-filtered'))
      .sort((a,b) => b.intersectionRatio - a.intersectionRatio)[0];
    if (!visible) return;
    links.forEach(link => link.classList.toggle('active', link.dataset.topicLink === visible.target.dataset.topic));
  }, { rootMargin: '-150px 0px -60% 0px', threshold: [0.05, 0.2, 0.5] });
  topics.forEach(topic => observer.observe(topic));

  const initial = root.dataset.initialTopic || '';
  if (initial) {
    requestAnimationFrame(() => document.getElementById(initial)?.scrollIntoView({ block: 'start' }));
  }
  filter();
})();
