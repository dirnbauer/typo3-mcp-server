/**
 * MCP Server Module - TYPO3 ES6 Module (token management + tab UI glue)
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class McpModule {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initialize());
        } else {
            this.initialize();
        }
    }

    initialize() {
        this.initTabs();

        document.querySelectorAll('.copy-button[data-copy-target]').forEach(button => {
            const targetId = button.getAttribute('data-copy-target');
            if (targetId) {
                button.addEventListener('click', () => this.copyToClipboard(targetId, button));
            }
        });

        document.querySelectorAll('[data-mcp-switch-tab]').forEach(button => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const targetId = button.getAttribute('data-mcp-switch-tab');
                if (targetId) {
                    this.activateTab(targetId);
                }
            });
        });

        const refreshTokensBtn = document.getElementById('refresh-tokens-btn');
        if (refreshTokensBtn) {
            refreshTokensBtn.addEventListener('click', () => this.refreshTokens());
        }

        const revokeAllTokensBtn = document.getElementById('revoke-all-tokens-btn');
        if (revokeAllTokensBtn) {
            revokeAllTokensBtn.addEventListener('click', () => this.revokeAllTokens());
        }

        const createTokenBtn = document.getElementById('create-token-btn');
        if (createTokenBtn) {
            createTokenBtn.addEventListener('click', () => this.showCreateTokenModal());
        }

        const refreshDiagnosticsBtn = document.getElementById('refresh-diagnostics-btn');
        if (refreshDiagnosticsBtn) {
            refreshDiagnosticsBtn.addEventListener('click', () => this.refreshDiagnostics(refreshDiagnosticsBtn));
        }

        document.addEventListener('click', (e) => {
            const button = e.target.classList.contains('revoke-token-btn')
                ? e.target
                : e.target.closest('.revoke-token-btn');
            if (!button) {
                return;
            }

            const tokenId = button.getAttribute('data-token-id');
            if (!tokenId) {
                return;
            }

            Modal.advanced({
                title: TYPO3.lang['js.revokeToken'],
                content: TYPO3.lang['js.revokeTokenConfirm'],
                severity: Severity.warning,
                buttons: [
                    {
                        text: TYPO3.lang['js.cancel'],
                        btnClass: 'btn-default',
                        trigger: () => Modal.dismiss(),
                    },
                    {
                        text: TYPO3.lang['js.revoke'],
                        btnClass: 'btn-warning',
                        trigger: () => {
                            Modal.dismiss();
                            this.revokeToken(tokenId);
                        },
                    },
                ],
            });
        });
    }

    initTabs() {
        document.querySelectorAll('[data-mcp-target]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const targetId = button.getAttribute('data-mcp-target');
                if (!targetId) {
                    return;
                }

                this.activateTab(targetId, button);
            });
        });
    }

    activateTab(targetId, triggerButton = null) {
        const targetPanel = document.getElementById(targetId);
        if (!targetPanel) {
            return;
        }

        const button = triggerButton ?? document.querySelector(`[data-mcp-target="${targetId}"]`);
        const nav = button?.closest('.nav');
        if (nav) {
            nav.querySelectorAll('[data-mcp-target]').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
        }
        if (button) {
            button.classList.add('active');
            button.setAttribute('aria-selected', 'true');
        }

        const panelGroup = targetPanel.parentElement;
        if (panelGroup) {
            panelGroup.querySelectorAll(':scope > .mcp-panel').forEach(panel => panel.classList.remove('mcp-active'));
        }
        targetPanel.classList.add('mcp-active');
    }

    copyToClipboard(elementId, button) {
        const element = document.getElementById(elementId);
        if (!element) {
            return;
        }

        const textToCopy = element.value ?? element.textContent ?? '';

        const write = () => {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(textToCopy);
            }

            element.focus();
            if (element.select) {
                element.select();
            }
            if (!document.execCommand('copy')) {
                throw new Error('copy failed');
            }
            return Promise.resolve();
        };

        write()
            .then(() => this.showCopyFeedback(button))
            .catch(() => {
                Notification.warning(TYPO3.lang['js.copyFailed'], TYPO3.lang['js.copyFailedMessage']);
            });
    }

    showCopyFeedback(button) {
        if (!button) {
            return;
        }

        const originalText = button.textContent;
        button.textContent = TYPO3.lang['js.copied'];
        button.classList.add('btn-success');
        button.classList.remove('btn-default', 'btn-outline-secondary');

        setTimeout(() => {
            button.textContent = originalText;
            button.classList.remove('btn-success');
            button.classList.add('btn-default');
        }, 2000);
    }

    getCsrfToken() {
        const container = document.getElementById('tokens-container');
        return container ? container.dataset.csrfToken || '' : '';
    }

    refreshTokens() {
        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_get_tokens)
            .post({})
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success) {
                    this.updateTokensTable(data.tokens);
                } else {
                    Notification.error(TYPO3.lang['js.refreshFailed'], data.message || '');
                }
            })
            .catch((error) => {
                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
            });
    }

    revokeToken(tokenId) {
        const tokenIdInt = parseInt(tokenId, 10);
        if (!tokenIdInt || tokenIdInt <= 0) {
            return;
        }

        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_revoke_token)
            .post({ tokenId: String(tokenIdInt), csrfToken: this.getCsrfToken() })
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success) {
                    Notification.success(TYPO3.lang['js.tokenRevoked'], data.message);
                    this.refreshTokens();
                } else {
                    Notification.error(TYPO3.lang['js.revokeFailed'], data.message || '');
                }
            })
            .catch((error) => {
                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
            });
    }

    revokeAllTokens() {
        Modal.advanced({
            title: TYPO3.lang['js.revokeAllTokens'],
            content: TYPO3.lang['js.revokeAllConfirm'],
            severity: Severity.warning,
            buttons: [
                {
                    text: TYPO3.lang['js.cancel'],
                    btnClass: 'btn-default',
                    trigger: () => Modal.dismiss(),
                },
                {
                    text: TYPO3.lang['js.revokeAll'],
                    btnClass: 'btn-warning',
                    trigger: () => {
                        Modal.dismiss();
                        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_revoke_all_tokens)
                            .post({ csrfToken: this.getCsrfToken() })
                            .then(async (response) => {
                                const data = await response.resolve();
                                if (data.success) {
                                    Notification.success(TYPO3.lang['js.tokensRevoked'], data.message);
                                    this.refreshTokens();
                                } else {
                                    Notification.error(TYPO3.lang['js.revokeFailed'], data.message || '');
                                }
                            })
                            .catch((error) => {
                                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
                            });
                    },
                },
            ],
        });
    }

    showCreateTokenModal() {
        const container = document.createElement('div');
        container.style.padding = '10px';

        const label = document.createElement('label');
        label.className = 'form-label';
        label.setAttribute('for', 'modal-token-name-input');
        label.textContent = TYPO3.lang['js.tokenName'];
        container.appendChild(label);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.id = 'modal-token-name-input';
        input.maxLength = 100;
        input.placeholder = TYPO3.lang['js.tokenNamePlaceholder'];
        container.appendChild(input);

        const hint = document.createElement('p');
        hint.className = 'text-muted small mt-2 mb-0';
        hint.textContent = TYPO3.lang['js.tokenNameHint'];
        container.appendChild(hint);

        const submit = () => {
            const name = input.value.trim();
            if (!name) {
                Notification.warning(TYPO3.lang['js.nameRequired'], TYPO3.lang['js.nameRequiredMessage']);
                return;
            }
            Modal.dismiss();
            this.createToken(name);
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submit();
            }
        });

        Modal.advanced({
            title: TYPO3.lang['js.createToken'],
            content: container,
            severity: Severity.info,
            buttons: [
                {
                    text: TYPO3.lang['js.cancel'],
                    btnClass: 'btn-default',
                    trigger: () => Modal.dismiss(),
                },
                {
                    text: TYPO3.lang['js.create'],
                    btnClass: 'btn-primary',
                    trigger: submit,
                },
            ],
        });

        setTimeout(() => {
            const modalEl = input.closest('.modal');
            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', () => input.focus(), { once: true });
            }
        }, 0);
    }

    createToken(clientName) {
        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_create_token)
            .post({ clientName, csrfToken: this.getCsrfToken() })
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success && data.token) {
                    this.showTokenModal(data.token, clientName);
                    this.refreshTokens();
                } else {
                    Notification.error(TYPO3.lang['js.tokenCreationFailed'], data.message || '');
                }
            })
            .catch((error) => {
                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
            });
    }

    showTokenModal(plainToken, clientName) {
        const container = document.createElement('div');
        container.style.padding = '10px';

        const warning = document.createElement('div');
        warning.className = 'alert alert-warning';
        warning.textContent = TYPO3.lang['js.tokenShownOnce'] + ' ' + TYPO3.lang['js.tokenCopyWarning'];
        container.appendChild(warning);

        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group mb-3';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control font-monospace';
        input.id = 'modal-token-value';
        input.value = plainToken;
        input.readOnly = true;
        inputGroup.appendChild(input);

        const copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-default';
        copyBtn.type = 'button';
        copyBtn.textContent = TYPO3.lang['js.copy'];
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(plainToken).then(() => {
                copyBtn.textContent = TYPO3.lang['js.copied'];
            });
        });
        inputGroup.appendChild(copyBtn);
        container.appendChild(inputGroup);

        if (clientName) {
            const label = document.createElement('p');
            label.className = 'mb-0';
            label.textContent = TYPO3.lang['js.tokenNameLabel'] + ' ' + clientName;
            container.appendChild(label);
        }

        Modal.advanced({
            title: TYPO3.lang['js.tokenCreated'],
            content: container,
            severity: Severity.ok,
            staticBackdrop: true,
            buttons: [
                {
                    text: TYPO3.lang['js.iHaveCopiedToken'],
                    btnClass: 'btn-primary',
                    trigger: () => Modal.dismiss(),
                },
            ],
        });
    }

    refreshDiagnostics(button) {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = TYPO3.lang['diagnostic.refreshing'] ?? originalText;

        new AjaxRequest(TYPO3.settings.ajaxUrls.mcp_server_run_diagnostics)
            .post({})
            .then(async (response) => {
                const data = await response.resolve();
                if (data.success && data.diagnosticsHtml) {
                    const container = document.getElementById('diagnostics-panel-content');
                    if (container) {
                        container.innerHTML = data.diagnosticsHtml;
                    }
                    Notification.success('', TYPO3.lang['diagnostic.refreshed'] ?? '');
                } else {
                    Notification.error(TYPO3.lang['diagnostic.refreshFailed'] ?? '', data.message || '');
                }
            })
            .catch((error) => {
                Notification.error(TYPO3.lang['diagnostic.refreshFailed'] ?? '', error.message || '');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    updateTokensTable(tokens) {
        const container = document.getElementById('tokens-container');
        if (!container) {
            return;
        }

        const csrf = container.dataset.csrfToken || '';

        if (!tokens || tokens.length === 0) {
            container.innerHTML = `
                <div id="no-tokens-message" class="text-center text-muted py-4">
                    <p>${this.escapeHtml(TYPO3.lang['tokens.noTokens'])}</p>
                    <p class="small mb-0">${this.escapeHtml(TYPO3.lang['tokens.noTokensHint'])}</p>
                </div>
            `;
            container.dataset.csrfToken = csrf;
            return;
        }

        const esc = (s) => this.escapeHtml(String(s ?? ''));
        container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>${esc(TYPO3.lang['tokens.clientName'])}</th>
                            <th>${esc(TYPO3.lang['tokens.created'])}</th>
                            <th>${esc(TYPO3.lang['tokens.lastUsed'])}</th>
                            <th>${esc(TYPO3.lang['tokens.expires'])}</th>
                            <th>${esc(TYPO3.lang['tokens.actions'])}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tokens.map(token => `
                            <tr data-token-id="${esc(token.uid)}">
                                <td><strong>${esc(token.client_name)}</strong></td>
                                <td><small class="text-muted">${esc(token.created)}</small></td>
                                <td><small class="text-muted">${esc(token.last_used)}</small></td>
                                <td><small class="text-muted">${esc(token.expires)}</small></td>
                                <td>
                                    <button class="btn btn-danger btn-sm revoke-token-btn" type="button" data-token-id="${esc(token.uid)}">
                                        ${esc(TYPO3.lang['tokens.revoke'])}
                                    </button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        container.dataset.csrfToken = csrf;
    }
}

export default new McpModule();
