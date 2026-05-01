async () => {
  const normalize = (text) => (text || '')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
  const cleanMessageText = (role, text) => {
    let cleaned = normalize(text)
      .replace(/^Drupal Guidance Assistant\s*/i, '')
      .replace(/\nUser$/i, '')
      .replace(/\nCopy message$/i, '')
      .trim();
    if (role === 'assistant' && cleaned === 'How can I help you work with this Drupal site?') {
      cleaned = '';
    }
    return cleaned;
  };

  const deepChat = document.querySelector('deep-chat');
  const shadowRoot = deepChat?.shadowRoot;
  const containers = shadowRoot
    ? Array.from(shadowRoot.querySelectorAll('.outer-message-container'))
    : [];

  const messages = containers
    .map((container, index) => {
      const className = String(container.className || '');
      const imageAlt = normalize(container.querySelector('img')?.getAttribute('alt') || '');
      let role = 'unknown';
      if (className.includes('role-user') || /user/i.test(imageAlt)) {
        role = 'user';
      }
      else if (className.includes('role-ai') || /ai|assistant|bot/i.test(imageAlt)) {
        role = 'assistant';
      }

      const clone = container.cloneNode(true);
      clone.querySelectorAll('img, svg, button, .copy, .avatar-container').forEach((node) => node.remove());
      const text = cleanMessageText(role, clone.innerText || clone.textContent || '');
      return text ? { index, role, text } : null;
    })
    .filter(Boolean);

  const headings = Array.from(document.querySelectorAll('h1, h2'))
    .slice(0, 8)
    .map((heading) => normalize(heading.innerText || heading.textContent || ''))
    .filter(Boolean);

  return {
    captured_at: new Date().toISOString(),
    url: window.location.href,
    path: window.location.pathname + window.location.search,
    title: document.title,
    page_headings: headings,
    deep_chat_present: Boolean(deepChat),
    message_count: messages.length,
    messages,
  };
}
