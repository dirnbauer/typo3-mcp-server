/**
 * Backend module "MCP Server".
 *
 * Tabs, collapsible panels and copy buttons are core elements; this module
 * only adds the token actions, the connection check refresh and the tool
 * filter. Markup for refreshed regions comes from the server (the same Fluid
 * partials the page renders), labels from the "mcp_server.mod" domain.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DebounceEvent from '@typo3/core/event/debounce-event.js';
import RegularEvent from '@typo3/core/event/regular-event.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { topLevelModuleImport } from '@typo3/backend/utility/top-level-module-import.js';
import { html } from 'lit';
import labels from '~labels/mcp_server.mod';

class McpServerModule {
  constructor() {
    this.root = document.querySelector('[data-mcp-module]');
    if (this.root === null) {
      return;
    }
    this.liveRegion = this.root.querySelector('[data-mcp-live-region]');

    // The docheader button lives outside the module body, so delegate from the document.
    new RegularEvent('click', (event, target) => {
      event.preventDefault();
      this.handleAction(target);
    }).delegateTo(document, '[data-mcp-action]');

    const toolFilter = this.root.querySelector('[data-mcp-tool-filter]');
    if (toolFilter !== null) {
      new DebounceEvent('input', () => this.filterTools(toolFilter.value), 200).bindTo(toolFilter);
    }
  }

  handleAction(target) {
    switch (target.dataset.mcpAction) {
      case 'create-token':
        this.showCreateTokenModal();
        break;
      case 'revoke-token':
        this.confirmRevokeToken(target.dataset.tokenId ?? '', target.dataset.tokenName ?? '');
        break;
      case 'revoke-all-tokens':
        this.confirmRevokeAllTokens();
        break;
      case 'refresh-diagnostics':
        this.refreshDiagnostics(target);
        break;
      case 'open-tab':
        this.openTab(target.dataset.mcpTab ?? '');
        break;
      default:
        break;
    }
  }

  showCreateTokenModal() {
    let submitted = false;
    const submit = (modal) => {
      const input = modal.querySelector('#mcp-token-name');
      const name = input?.value.trim() ?? '';
      if (name === '') {
        input?.setAttribute('aria-invalid', 'true');
        input?.classList.add('is-invalid');
        modal.querySelector('#mcp-token-name-error')?.removeAttribute('hidden');
        input?.focus();
        return;
      }
      if (submitted) {
        return;
      }
      submitted = true;
      modal.hideModal();
      this.createToken(name);
    };

    const modal = Modal.advanced({
      title: labels.get('js.createToken.title'),
      severity: Severity.notice,
      content: html`
        <form novalidate @submit=${(event) => { event.preventDefault(); submit(modal); }}>
          <div class="form-group mb-0">
            <label class="form-label" for="mcp-token-name">${labels.get('js.tokenName')}</label>
            <input
              class="form-control"
              id="mcp-token-name"
              name="clientName"
              type="text"
              maxlength="100"
              autocomplete="off"
              required
              aria-describedby="mcp-token-name-hint mcp-token-name-error"
              placeholder=${labels.get('js.tokenNamePlaceholder')}
            >
            <p class="form-text" id="mcp-token-name-hint">${labels.get('js.tokenNameHint')}</p>
            <p class="invalid-feedback d-block" id="mcp-token-name-error" hidden>${labels.get('js.nameRequired')}</p>
          </div>
        </form>`,
      buttons: [
        {
          text: labels.get('js.cancel'),
          btnClass: 'btn-default',
          name: 'cancel',
          trigger: (event, modalElement) => modalElement.hideModal(),
        },
        {
          text: labels.get('js.create'),
          btnClass: 'btn-primary',
          name: 'create',
          trigger: (event, modalElement) => submit(modalElement),
        },
      ],
    });
    modal.addEventListener('typo3-modal-shown', () => modal.querySelector('#mcp-token-name')?.focus());
  }

  async createToken(clientName) {
    try {
      const data = await this.post('mcp_server_create_token', { clientName, csrfToken: this.csrfToken() });
      await this.showTokenModal(data.token, clientName);
      await this.refreshTokens();
      // Behind the modal, the list with the new token becomes the visible tab.
      this.openTab('#mcp-tab-tokens', false);
      this.announce(data.message ?? labels.get('js.tokenCreated'));
    } catch (error) {
      Notification.error(labels.get('js.tokenCreationFailed'), error.message);
    }
  }

  /**
   * The plaintext token is shown exactly once, in a modal that only closes
   * through its button. The modal renders in the top frame, so the copy
   * element has to be defined there as well.
   */
  async showTokenModal(token, clientName) {
    await this.importIntoModalFrame('@typo3/backend/copy-to-clipboard.js');
    Modal.advanced({
      title: labels.get('js.tokenCreated'),
      severity: Severity.ok,
      staticBackdrop: true,
      hideCloseButton: true,
      content: html`
        <div class="callout callout-warning" role="alert">
          <div class="callout-content">
            <div class="callout-body">${labels.get('js.tokenShownOnce')}</div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="mcp-token-value">${labels.get('js.tokenValue', { name: clientName })}</label>
          <div class="input-group">
            <input class="form-control font-monospace" id="mcp-token-value" type="text" readonly .value=${token}>
            <typo3-copy-to-clipboard class="btn btn-default" .text=${token}>${labels.get('js.copyToken')}</typo3-copy-to-clipboard>
          </div>
        </div>`,
      buttons: [
        {
          text: labels.get('js.tokenCopied'),
          btnClass: 'btn-primary',
          name: 'done',
          trigger: (event, modalElement) => modalElement.hideModal(),
        },
      ],
    });
  }

  confirmRevokeToken(tokenId, tokenName) {
    const modal = Modal.confirm(
      labels.get('js.revokeToken.title'),
      labels.get('js.revokeToken.message', { name: tokenName }),
      Severity.warning,
      [
        { text: labels.get('js.cancel'), btnClass: 'btn-default', name: 'cancel', active: true },
        { text: labels.get('js.revoke'), btnClass: 'btn-warning', name: 'revoke' },
      ],
    );
    modal.addEventListener('button.clicked', async (event) => {
      modal.hideModal();
      if (event.target.getAttribute('name') !== 'revoke') {
        return;
      }
      try {
        const data = await this.post('mcp_server_revoke_token', { tokenId, csrfToken: this.csrfToken() });
        Notification.success(labels.get('js.tokenRevoked'), data.message ?? '');
        await this.refreshTokens();
        this.announce(labels.get('js.tokenRevoked'));
      } catch (error) {
        Notification.error(labels.get('js.revokeFailed'), error.message);
      }
    });
  }

  confirmRevokeAllTokens() {
    const modal = Modal.confirm(
      labels.get('js.revokeAll.title'),
      labels.get('js.revokeAll.message'),
      Severity.warning,
      [
        { text: labels.get('js.cancel'), btnClass: 'btn-default', name: 'cancel', active: true },
        { text: labels.get('js.revokeAll'), btnClass: 'btn-danger', name: 'revoke-all' },
      ],
    );
    modal.addEventListener('button.clicked', async (event) => {
      modal.hideModal();
      if (event.target.getAttribute('name') !== 'revoke-all') {
        return;
      }
      try {
        const data = await this.post('mcp_server_revoke_all_tokens', { csrfToken: this.csrfToken() });
        Notification.success(labels.get('js.tokensRevoked'), data.message ?? '');
        await this.refreshTokens();
        this.announce(labels.get('js.tokensRevoked'));
      } catch (error) {
        Notification.error(labels.get('js.revokeFailed'), error.message);
      }
    });
  }

  async refreshTokens() {
    const container = this.root.querySelector('[data-mcp-tokens]');
    if (container === null) {
      return;
    }
    container.setAttribute('aria-busy', 'true');
    try {
      const data = await this.post('mcp_server_get_tokens', {});
      container.innerHTML = data.html;
      const counter = this.root.querySelector('[data-mcp-token-count]');
      if (counter !== null) {
        counter.textContent = String(data.count);
      }
    } catch (error) {
      Notification.error(labels.get('js.refreshFailed'), error.message);
    } finally {
      container.removeAttribute('aria-busy');
    }
  }

  async refreshDiagnostics(button) {
    const container = this.root.querySelector('[data-mcp-diagnostics]');
    if (container === null) {
      return;
    }
    button.disabled = true;
    container.setAttribute('aria-busy', 'true');
    this.announce(labels.get('js.diagnostics.running'));
    try {
      const data = await this.post('mcp_server_run_diagnostics', {});
      container.innerHTML = data.diagnosticsHtml;
      this.updateCheckBadge(data.overallStatus);
      this.announce(labels.get('js.diagnostics.updated'));
    } catch (error) {
      Notification.error(labels.get('js.diagnostics.failed'), error.message);
    } finally {
      button.disabled = false;
      container.removeAttribute('aria-busy');
    }
  }

  updateCheckBadge(status) {
    const slot = this.root.querySelector('[data-mcp-check-badge]');
    if (slot === null) {
      return;
    }
    slot.replaceChildren();
    const variants = { error: 'badge-danger', warning: 'badge-warning' };
    if (variants[status] === undefined) {
      return;
    }
    const badge = document.createElement('span');
    badge.className = 'badge ' + variants[status];
    badge.textContent = labels.get('diagnostic.status.' + status);
    slot.append(badge);
  }

  filterTools(query) {
    const needle = query.trim().toLowerCase();
    let visible = 0;
    this.root.querySelectorAll('[data-mcp-tool]').forEach((row) => {
      const matches = needle === '' || row.dataset.mcpTool.includes(needle);
      row.hidden = !matches;
      if (matches) {
        visible++;
      }
    });
    const emptyRow = this.root.querySelector('[data-mcp-tool-empty]');
    if (emptyRow !== null) {
      emptyRow.hidden = visible > 0;
    }
    this.announce(labels.get('js.tools.count', { count: visible }));
  }

  openTab(selector, focus = true) {
    const tab = this.root.querySelector('[data-typo3-tab="' + selector + '"]');
    if (tab === null) {
      return;
    }
    tab.click();
    if (focus) {
      tab.focus();
    }
  }

  /**
   * POST to a backend AJAX route and return the decoded JSON body. HTTP
   * errors reject with the server's translated message.
   */
  async post(route, data) {
    let response;
    try {
      response = await new AjaxRequest(TYPO3.settings.ajaxUrls[route]).post(data);
    } catch (error) {
      throw new Error(await this.errorMessage(error));
    }
    const body = await response.resolve();
    if (body?.success !== true) {
      throw new Error(body?.message || labels.get('js.requestFailed'));
    }
    return body;
  }

  async errorMessage(error) {
    if (typeof error?.resolve === 'function') {
      try {
        const body = await error.resolve();
        if (typeof body?.message === 'string' && body.message !== '') {
          return body.message;
        }
      } catch {
        // Not JSON: fall through to the generic message.
      }
    }
    return labels.get('js.requestFailed');
  }

  async importIntoModalFrame(specifier) {
    if (window.location !== window.parent.location) {
      await topLevelModuleImport(specifier);
    } else {
      await import(specifier);
    }
  }

  csrfToken() {
    return this.root.dataset.csrfToken ?? '';
  }

  announce(message) {
    if (this.liveRegion === null) {
      return;
    }
    // Clearing first makes screen readers repeat an identical message.
    this.liveRegion.textContent = '';
    window.setTimeout(() => {
      this.liveRegion.textContent = message;
    }, 50);
  }
}

export default new McpServerModule();
