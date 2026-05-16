/**
 * MCP Server Module - TYPO3 ES6 Module
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class McpModule {
    constructor() {
        // ES6 modules via includeJavaScriptModules are typically deferred,
        // but guard against edge cases where readyState may still be 'loading'.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initialize());
        } else {
            this.initialize();
        }
    }

    initialize() {
        this.initTabs();

        // Copy buttons
        document.querySelectorAll('.copy-button[data-copy-target]').forEach(button => {
            const targetId = button.getAttribute('data-copy-target');
            if (targetId) {
                button.addEventListener('click', () => this.copyToClipboard(targetId, button));
            }
        });

        // Token management buttons
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

        // Delegated revoke button handler
        document.addEventListener('click', (e) => {
            const button = e.target.classList.contains('revoke-token-btn')
                ? e.target
                : e.target.closest('.revoke-token-btn');
            if (!button) return;

            const tokenId = button.getAttribute('data-token-id');
            if (!tokenId) return;

            Modal.advanced({
                title: TYPO3.lang['js.revokeToken'],
                content: TYPO3.lang['js.revokeTokenConfirm'],
                severity: Severity.warning,
                buttons: [
                    {
                        text: TYPO3.lang['js.cancel'],
                        btnClass: 'btn-default',
                        trigger: () => Modal.dismiss()
                    },
                    {
                        text: TYPO3.lang['js.revoke'],
                        btnClass: 'btn-warning',
                        trigger: () => {
                            Modal.dismiss();
                            this.revokeToken(tokenId);
                        }
                    }
                ]
            });
        });

        // Check endpoint statuses
        this.checkEndpointStatuses();
    }

    // =========================================================================
    // Tabs — custom panel switching, no Bootstrap dependency
    // =========================================================================

    initTabs() {
        document.querySelectorAll('[data-mcp-target]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const targetId = button.getAttribute('data-mcp-target');
                const targetPanel = document.getElementById(targetId);
                if (!targetPanel) return;

                const nav = button.closest('.nav');
                if (nav) {
                    nav.querySelectorAll('[data-mcp-target]').forEach(b => b.classList.remove('active'));
                }
                button.classList.add('active');

                const panelGroup = targetPanel.parentElement;
                if (panelGroup) {
                    panelGroup.querySelectorAll(':scope > .mcp-panel').forEach(p => p.classList.remove('mcp-active'));
                }
                targetPanel.classList.add('mcp-active');
            });
        });
    }

    // =========================================================================
    // Clipboard
    // =========================================================================

    copyToClipboard(elementId, button) {
        const element = document.getElementById(elementId);
        if (!element) {
            console.error('Element not found:', elementId);
            return;
        }

        let textToCopy = element.value;
        let selectionStart = 0;
        let selectionEnd = textToCopy.length;

        const serverKey = button.getAttribute('data-copy-server-only');
        if (serverKey) {
            const result = this.extractServerConfigWithPosition(textToCopy, serverKey);
            textToCopy = result.config;
            selectionStart = result.start;
            selectionEnd = result.end;
        }

        element.focus();
        element.setSelectionRange(selectionStart, selectionEnd);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(() => {
                this.showCopyFeedback(button);
            }).catch(() => {
                this.fallbackCopyWithText(textToCopy, button);
            });
        } else {
            try {
                const success = document.execCommand('copy');
                if (success) {
                    this.showCopyFeedback(button);
                } else {
                    Notification.warning(TYPO3.lang['js.copyFailed'], TYPO3.lang['js.copyFailedMessage']);
                }
            } catch {
                Notification.warning(TYPO3.lang['js.copyFailed'], TYPO3.lang['js.copyFailedMessage']);
            }
        }
    }

    extractServerConfigWithPosition(fullConfig, serverKey) {
        try {
            const config = JSON.parse(fullConfig);
            const serverConfig = config.mcpServers[serverKey];
            const serverConfigJson = JSON.stringify(serverConfig, null, 2);

            const serverKeyPattern = new RegExp(`"${serverKey}"\\s*:\\s*{`, 'g');
            const match = serverKeyPattern.exec(fullConfig);

            if (match) {
                const colonIndex = fullConfig.indexOf(':', match.index);
                let start = fullConfig.indexOf('{', colonIndex);
                let braceCount = 1;
                let end = start + 1;

                while (end < fullConfig.length && braceCount > 0) {
                    if (fullConfig[end] === '{') braceCount++;
                    else if (fullConfig[end] === '}') braceCount--;
                    end++;
                }

                return { config: serverConfigJson, start, end };
            }

            return { config: serverConfigJson, start: 0, end: fullConfig.length };
        } catch {
            return { config: fullConfig, start: 0, end: fullConfig.length };
        }
    }

    fallbackCopyWithText(text, button) {
        const tempTextarea = document.createElement('textarea');
        tempTextarea.value = text;
        tempTextarea.style.position = 'fixed';
        tempTextarea.style.left = '-999999px';
        tempTextarea.style.top = '-999999px';
        document.body.appendChild(tempTextarea);

        tempTextarea.focus();
        tempTextarea.select();

        try {
            const success = document.execCommand('copy');
            if (success) {
                this.showCopyFeedback(button);
            } else {
                Notification.warning(TYPO3.lang['js.copyFailed'], TYPO3.lang['js.copyFailedMessage']);
            }
        } catch {
            Notification.warning(TYPO3.lang['js.copyFailed'], TYPO3.lang['js.copyFailedMessage']);
        } finally {
            document.body.removeChild(tempTextarea);
        }
    }

    showCopyFeedback(button) {
        if (!button) return;

        const originalWidth = button.offsetWidth;
        const iconMarkup = button.querySelector('.icon-markup');
        const textNodes = Array.from(button.childNodes).filter(node => node.nodeType === Node.TEXT_NODE);
        const lastTextNode = textNodes[textNodes.length - 1];

        const originalIconText = iconMarkup ? iconMarkup.textContent : '';
        const originalButtonText = lastTextNode ? lastTextNode.textContent : '';

        button.style.width = originalWidth + 'px';

        if (iconMarkup) iconMarkup.textContent = '✅';
        if (lastTextNode) lastTextNode.textContent = ' ' + TYPO3.lang['js.copied'];

        button.classList.add('btn-success');
        button.classList.remove('btn-outline-secondary');

        setTimeout(() => {
            if (iconMarkup) iconMarkup.textContent = originalIconText;
            if (lastTextNode) lastTextNode.textContent = originalButtonText;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-secondary');
            button.style.width = '';
        }, 2000);
    }

    // =========================================================================
    // Token CRUD
    // =========================================================================

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
                    Notification.error(TYPO3.lang['js.refreshFailed'], TYPO3.lang['js.refreshFailedMessage'].replace('%s', data.message));
                }
            })
            .catch((error) => {
                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
            });
    }

    revokeToken(tokenId) {
        const tokenIdInt = parseInt(tokenId, 10);

        if (!tokenIdInt || tokenIdInt <= 0) {
            Notification.error(TYPO3.lang['js.invalidToken'], tokenId);
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
                    Notification.error(TYPO3.lang['js.revokeFailed'], TYPO3.lang['js.revokeFailedMessage'].replace('%s', data.message));
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
                    trigger: () => Modal.dismiss()
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
                                    Notification.error(TYPO3.lang['js.revokeFailed'], TYPO3.lang['js.revokeAllFailed'].replace('%s', data.message));
                                }
                            })
                            .catch((error) => {
                                Notification.error(TYPO3.lang['js.networkError'], error.message || '');
                            });
                    }
                }
            ]
        });
    }

    /**
     * Show modal to name the new token before creating it.
     */
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
                    trigger: () => Modal.dismiss()
                },
                {
                    text: TYPO3.lang['js.create'],
                    btnClass: 'btn-primary',
                    trigger: submit
                }
            ]
        });

        // Focus the input after the Bootstrap modal transition completes.
        // TYPO3's Modal moves our content to the top frame, so use
        // input.closest('.modal') to find the actual modal element.
        setTimeout(() => {
            const modalEl = input.closest('.modal');
            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', () => input.focus(), { once: true });
            }
        }, 0);
    }

    /**
     * Create a token via AJAX and show the "show once" modal.
     */
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

    /**
     * Display a TYPO3 Modal with the plain token (shown only once).
     */
    showTokenModal(plainToken, clientName) {
        const container = document.createElement('div');
        container.style.padding = '10px';

        const warning = document.createElement('div');
        warning.className = 'alert alert-warning';
        const warningStrong = document.createElement('strong');
        warningStrong.textContent = TYPO3.lang['js.tokenShownOnce'];
        warning.appendChild(warningStrong);
        warning.appendChild(document.createTextNode(' ' + TYPO3.lang['js.tokenCopyWarning']));
        container.appendChild(warning);

        if (clientName) {
            const label = document.createElement('p');
            const strong = document.createElement('strong');
            strong.textContent = TYPO3.lang['js.tokenNameLabel'] + ' ';
            label.appendChild(strong);
            label.appendChild(document.createTextNode(clientName));
            container.appendChild(label);
        }

        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group mb-3';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.value = plainToken;
        input.readOnly = true;
        input.style.fontFamily = 'monospace';
        input.id = 'modal-token-value';
        inputGroup.appendChild(input);

        const copyBtn = document.createElement('button');
        copyBtn.className = 'btn btn-outline-secondary';
        copyBtn.type = 'button';
        copyBtn.textContent = TYPO3.lang['js.copy'];
        copyBtn.addEventListener('click', () => {
            const onSuccess = () => {
                copyBtn.textContent = TYPO3.lang['js.copied'];
                copyBtn.classList.add('btn-success');
                copyBtn.classList.remove('btn-outline-secondary');
            };
            // The modal lives in the top frame (TYPO3 Modal API), so use
            // input.ownerDocument for execCommand — not the iframe's document.
            const doc = input.ownerDocument;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(plainToken).then(onSuccess).catch(() => {
                    input.focus();
                    input.select();
                    doc.execCommand('copy');
                    onSuccess();
                });
            } else {
                input.focus();
                input.select();
                if (doc.execCommand('copy')) {
                    onSuccess();
                } else {
                    copyBtn.textContent = TYPO3.lang['js.selectAndCopy'];
                }
            }
        });
        inputGroup.appendChild(copyBtn);
        container.appendChild(inputGroup);

        Modal.advanced({
            title: TYPO3.lang['js.tokenCreated'],
            content: container,
            severity: Severity.ok,
            staticBackdrop: true,
            buttons: [
                {
                    text: TYPO3.lang['js.iHaveCopiedToken'],
                    btnClass: 'btn-primary',
                    trigger: () => {
                        Modal.dismiss();
                    }
                }
            ]
        });

        // Focus and select the token value after the modal transition so
        // the user can immediately Cmd+C / Ctrl+C.
        setTimeout(() => {
            const modalEl = input.closest('.modal');
            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', () => {
                    input.focus();
                    input.select();
                }, { once: true });
            }
        }, 0);
    }

    // =========================================================================
    // Token Table
    // =========================================================================

    /**
     * Escape HTML special characters to prevent XSS when building innerHTML.
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str ?? '')));
        return div.innerHTML;
    }

    updateTokensTable(tokens) {
        const container = document.getElementById('tokens-container');
        if (!container) return;

        if (!tokens || tokens.length === 0) {
            container.innerHTML = `
                <div id="no-tokens-message" class="text-center text-muted py-4">
                    <p>${this.escapeHtml(TYPO3.lang['tokens.noTokens'])}</p>
                    <p class="small">${this.escapeHtml(TYPO3.lang['tokens.noTokensHint'])}</p>
                </div>
            `;
        } else {
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
                                        <button class="btn btn-sm btn-danger revoke-token-btn" data-token-id="${esc(token.uid)}" aria-label="Revoke token for ${esc(token.client_name)}">
                                            <span class="mcp-btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                            ${esc(TYPO3.lang['tokens.revoke'])}
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
    }

    // =========================================================================
    // Endpoint Status Checks (use raw fetch — these are cross-origin requests)
    // =========================================================================

    checkEndpointStatuses() {
        document.querySelectorAll('.endpoint-status').forEach(element => {
            const endpoint = element.getAttribute('data-endpoint');
            const checkContent = element.getAttribute('data-check-content') === 'true';
            const checkAuth = element.getAttribute('data-check-auth') === 'true';

            if (endpoint) {
                if (checkAuth) {
                    this.checkMcpEndpointAuth(element, endpoint);
                } else {
                    this.checkEndpoint(element, endpoint, checkContent);
                }
            }
        });
    }

    checkEndpoint(element, endpoint, checkContent) {
        element.classList.add('checking');
        element.classList.remove('success', 'warning', 'error');

        fetch(endpoint, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            mode: 'cors',
            credentials: 'omit'
        })
            .then(response => {
                if (response.ok) {
                    if (checkContent) {
                        return response.text().then(text => {
                            if (text.includes('/mcp')) {
                                this.setEndpointStatus(element, 'success', TYPO3.lang['js.endpointWorking']);
                            } else {
                                this.setEndpointStatus(element, 'warning', TYPO3.lang['js.endpointNoMcp']);
                            }
                        });
                    }
                    return this.setEndpointStatus(element, 'success', TYPO3.lang['js.endpointReachable']);
                } else {
                    this.setEndpointStatus(element, 'error', `Endpoint returned ${response.status} ${response.statusText}`);
                }
            })
            .catch(error => {
                if (error.message.includes('CORS') || error.message.includes('blocked')) {
                    this.setEndpointStatus(element, 'error', TYPO3.lang['js.endpointCorsBlocked']);
                } else {
                    this.setEndpointStatus(element, 'error', `Network error: ${error.message}`);
                }
            });
    }

    setEndpointStatus(element, status, message) {
        element.classList.remove('checking', 'success', 'warning', 'error');
        element.classList.add(status);

        const statusTooltip = element.querySelector('.status-tooltip');
        if (statusTooltip) {
            statusTooltip.textContent = message;
        }
    }

    checkMcpEndpointAuth(element, endpoint) {
        element.classList.add('checking');
        element.classList.remove('success', 'warning', 'error');

        fetch(endpoint + '?test=auth', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer test-header-check-12345'
            },
            mode: 'cors',
            credentials: 'omit'
        })
            .then(response => {
                return response.json().then(data => {
                    if (data.headers_received && data.headers_received.authorization) {
                        this.setEndpointStatus(element, 'success', TYPO3.lang['js.endpointAuthOk']);
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) warningDiv.style.display = 'none';
                    } else {
                        this.setEndpointStatus(element, 'error', TYPO3.lang['js.endpointAuthFail']);
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) warningDiv.style.display = 'block';
                    }
                }).catch(() => {
                    if (response.status === 401) {
                        this.setEndpointStatus(element, 'warning', TYPO3.lang['js.endpointHttpBasicAuth']);
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) {
                            warningDiv.style.display = 'block';
                        }
                    } else {
                        this.setEndpointStatus(element, 'error', `MCP endpoint returned ${response.status} ${response.statusText}`);
                    }
                });
            })
            .catch(error => {
                if (error.message.includes('CORS') || error.message.includes('blocked')) {
                    this.setEndpointStatus(element, 'error', TYPO3.lang['js.endpointCorsBlocked']);
                } else {
                    this.setEndpointStatus(element, 'error', `Network error: ${error.message}`);
                }
                const warningDiv = document.getElementById('auth-header-warning');
                if (warningDiv) warningDiv.style.display = 'block';
            });
    }
}

export default new McpModule();
