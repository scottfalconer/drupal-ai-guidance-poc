(function (Drupal, once) {
  'use strict';

  const MAX_MESSAGES = 8;
  const MAX_MESSAGE_LENGTH = 500;
  const MAX_FORM_FIELDS = 24;
  const MAX_FORM_BUTTONS = 8;

  function normalizeText(text) {
    return String(text || '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function messageType(element) {
    const className = element.className || '';
    if (String(className).includes('messages--error')) {
      return 'error';
    }
    if (String(className).includes('messages--warning')) {
      return 'warning';
    }
    return 'status';
  }

  function visiblePageMessages() {
    const messages = [];
    const selectors = [
      '[data-drupal-messages] [role="alert"]',
      '[data-drupal-messages] .messages',
      '.messages[role="contentinfo"]',
      '.messages',
    ];

    document.querySelectorAll(selectors.join(',')).forEach((element) => {
      if (messages.length >= MAX_MESSAGES) {
        return;
      }
      if (element.closest('deep-chat')) {
        return;
      }
      const text = normalizeText(element.innerText || element.textContent || '');
      if (!text) {
        return;
      }
      messages.push({
        type: messageType(element),
        text: text.slice(0, MAX_MESSAGE_LENGTH),
      });
    });

    return messages;
  }

  function localPath(value) {
    try {
      const url = new URL(value || window.location.href, window.location.origin);
      if (url.origin !== window.location.origin) {
        return '';
      }
      return url.pathname;
    }
    catch (e) {
      return '';
    }
  }

  function fieldLabel(field, form) {
    const id = field.getAttribute('id');
    if (id) {
      const label = form.querySelector(`label[for="${CSS.escape(id)}"]`);
      if (label) {
        return normalizeText(label.innerText || label.textContent || '').replace(/\s*\*\s*$/, '');
      }
    }

    const wrapperLabel = field.closest('label');
    if (wrapperLabel) {
      return normalizeText(wrapperLabel.innerText || wrapperLabel.textContent || '').replace(/\s*\*\s*$/, '');
    }

    return normalizeText(field.getAttribute('aria-label') || field.getAttribute('placeholder') || field.getAttribute('name') || '');
  }

  function visibleFormSummary() {
    const forms = Array.from(document.querySelectorAll('form')).filter((form) => !form.closest('deep-chat'));
    if (!forms.length) {
      return null;
    }

    const form = forms.find((candidate) => {
      const id = candidate.getAttribute('id') || '';
      const formId = candidate.querySelector('input[name="form_id"]');
      const formIdValue = formId ? formId.value || '' : '';
      return candidate.classList.contains('node-form')
        || id.includes('node-')
        || formIdValue.includes('node_')
        || candidate.querySelector('[data-drupal-selector^="edit-title"]');
    }) || forms[0];

    const formIdInput = form.querySelector('input[name="form_id"]');
    const fields = [];
    form.querySelectorAll('input, select, textarea').forEach((field) => {
      if (fields.length >= MAX_FORM_FIELDS) {
        return;
      }
      const type = String(field.getAttribute('type') || field.tagName || '').toLowerCase();
      if (['hidden', 'submit', 'button', 'image', 'reset'].includes(type)) {
        return;
      }
      const name = field.getAttribute('name') || '';
      if (!name || name.startsWith('form_') || name === 'op') {
        return;
      }
      const value = safeFieldValue(field, type, name);
      fields.push({
        name: name.slice(0, 120),
        label: fieldLabel(field, form).slice(0, 160),
        type: type.slice(0, 40),
        required: field.required
          || field.getAttribute('aria-required') === 'true'
          || Boolean(field.closest('.form-required, .js-form-required')),
        ...(value ? { value } : {}),
      });
    });

    const submitButtons = [];
    form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
      if (submitButtons.length >= MAX_FORM_BUTTONS) {
        return;
      }
      const label = normalizeText(button.value || button.innerText || button.textContent || button.getAttribute('aria-label') || '');
      if (label) {
        submitButtons.push(label.slice(0, 120));
      }
    });

    return {
      form_id: formIdInput ? String(formIdInput.value || '').slice(0, 160) : '',
      action: localPath(form.getAttribute('action') || window.location.href),
      method: String(form.getAttribute('method') || 'get').toLowerCase().slice(0, 12),
      fields,
      submit_buttons: submitButtons,
    };
  }

  function safeFieldValue(field, type, name) {
    const lowerName = String(name || '').toLowerCase();
    if (['password', 'file', 'checkbox', 'radio'].includes(type)
      || lowerName.includes('token')
      || lowerName.includes('pass')
      || lowerName.includes('secret')
      || lowerName.includes('key')
      || lowerName.includes('mail')) {
      return '';
    }
    if (!['text', 'textarea', 'number', 'search', 'url', 'tel', 'select'].includes(type)
      && field.tagName.toLowerCase() !== 'textarea'
      && field.tagName.toLowerCase() !== 'select') {
      return '';
    }
    const value = normalizeText(field.value || '');
    if (!value || value === '- Any -') {
      return '';
    }
    return value.slice(0, 700);
  }

  function applyContext(request) {
    if (!request || typeof request !== 'object') {
      return request;
    }
    request.body = request.body || {};
    request.body.contexts = request.body.contexts || {};
    request.body.contexts.current_route = window.location.pathname;

    const messages = visiblePageMessages();
    if (messages.length) {
      request.body.contexts.visible_page_messages = messages;
    }

    const form = visibleFormSummary();
    if (form) {
      request.body.contexts.current_form = form;
    }

    return request;
  }

  function wrapDeepChat(deepChat) {
    if (!deepChat || (deepChat.requestInterceptor && deepChat.requestInterceptor.aiGuidancePageContextWrapped)) {
      return;
    }

    const originalInterceptor = deepChat.requestInterceptor;
    const wrappedInterceptor = async function (request) {
      const updatedRequest = applyContext(request);
      if (typeof originalInterceptor === 'function') {
        const result = await originalInterceptor.call(this, updatedRequest);
        return result || updatedRequest;
      }
      return updatedRequest;
    };
    wrappedInterceptor.aiGuidancePageContextWrapped = true;
    deepChat.requestInterceptor = wrappedInterceptor;
  }

  Drupal.behaviors.aiGuidancePageContext = {
    attach(context) {
      once('ai-guidance-page-context', 'deep-chat', context).forEach((deepChat) => {
        let attempts = 0;
        const timer = window.setInterval(() => {
          wrapDeepChat(deepChat);
          attempts++;
          if (attempts >= 10) {
            window.clearInterval(timer);
          }
        }, 250);
      });
    },
  };
})(Drupal, once);
