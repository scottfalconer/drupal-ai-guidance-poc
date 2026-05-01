async () => {
  const options = {
    initialHoldMs: 1200,
    betweenHoldMs: 850,
    finalHoldMs: 1200,
    segmentDurationMs: 950,
    maxSegmentPx: 360,
    viewportFraction: 0.58,
    topPaddingPx: 10,
    bottomPaddingPx: 18,
  };

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const easeInOutCubic = (value) => (
    value < 0.5
      ? 4 * value * value * value
      : 1 - Math.pow(-2 * value + 2, 3) / 2
  );

  const deepChat = document.querySelector('deep-chat');
  const shadowRoot = deepChat?.shadowRoot;
  if (!shadowRoot) {
    return { ok: false, reason: 'No deep-chat shadow root found.' };
  }

  const scroller =
    shadowRoot.querySelector('#messages') ||
    shadowRoot.querySelector('[class*="messages"]') ||
    shadowRoot.querySelector('[style*="overflow"]');

  if (!scroller) {
    return { ok: false, reason: 'No chat message scroller found.' };
  }

  const containers = Array.from(shadowRoot.querySelectorAll('.outer-message-container'))
    .filter((container) => (container.innerText || container.textContent || '').trim());
  const assistantMessages = containers.filter((container) => {
    const className = String(container.className || '');
    const imageAlt = container.querySelector('img')?.getAttribute('alt') || '';
    return className.includes('role-ai') || /ai|assistant|bot/i.test(imageAlt);
  });
  const latest = assistantMessages[assistantMessages.length - 1];

  if (!latest) {
    return { ok: false, reason: 'No assistant message found.' };
  }

  const maxScroll = () => Math.max(0, scroller.scrollHeight - scroller.clientHeight);
  const topFor = (element) => {
    const scrollerRect = scroller.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    return scroller.scrollTop + elementRect.top - scrollerRect.top - options.topPaddingPx;
  };
  const bottomFor = (element) => {
    const scrollerRect = scroller.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    return scroller.scrollTop + elementRect.bottom - scrollerRect.top - scroller.clientHeight + options.bottomPaddingPx;
  };

  const animateScroll = async (from, to) => {
    if (Math.abs(to - from) < 2) {
      scroller.scrollTop = to;
      return;
    }
    const start = performance.now();
    await new Promise((resolve) => {
      const frame = (now) => {
        const progress = clamp((now - start) / options.segmentDurationMs, 0, 1);
        scroller.scrollTop = from + (to - from) * easeInOutCubic(progress);
        if (progress < 1) {
          requestAnimationFrame(frame);
        }
        else {
          resolve();
        }
      };
      requestAnimationFrame(frame);
    });
  };

  const start = clamp(topFor(latest), 0, maxScroll());
  scroller.scrollTop = start;
  latest.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  await sleep(options.initialHoldMs);

  const end = clamp(bottomFor(latest), 0, maxScroll());
  const segment = Math.max(
    120,
    Math.min(options.maxSegmentPx, Math.floor(scroller.clientHeight * options.viewportFraction)),
  );
  let current = scroller.scrollTop;
  let steps = 0;

  while (current < end - 2 && steps < 12) {
    const next = Math.min(end, current + segment);
    await animateScroll(current, next);
    current = scroller.scrollTop;
    steps += 1;
    if (current < end - 2) {
      await sleep(options.betweenHoldMs);
    }
  }

  await sleep(options.finalHoldMs);

  return {
    ok: true,
    scrollerHeight: scroller.clientHeight,
    messageHeight: Math.round(latest.getBoundingClientRect().height),
    start: Math.round(start),
    end: Math.round(end),
    finalScrollTop: Math.round(scroller.scrollTop),
    steps,
  };
}
