function getCopyValue(element) {
    if ('value' in element) {
        return element.value;
    }

    return element.textContent || '';
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function getCsrfToken() {
    const el = document.querySelector('[data-csrf-token]');
    return el ? el.dataset.csrfToken : '';
}

function getJsonFetchOptions(body) {
    const csrfToken = getCsrfToken();
    const payload = body !== undefined ? { ...body, csrfToken } : { csrfToken };

    return {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    };
}

function copyToClipboard(elementId, button) {
    const element = document.getElementById(elementId);
    if (!element) {
        return;
    }

    const textToCopy = getCopyValue(element);

    if ('select' in element) {
        element.focus();
        element.select();
    }

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy)
            .then(() => showCopyFeedback(button))
            .catch(() => fallbackCopyWithText(textToCopy, button));
        return;
    }

    fallbackCopyWithText(textToCopy, button);
}

function fallbackCopyWithText(text, button) {
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
            showCopyFeedback(button);
        } else {
            showManualCopyMessage();
        }
    } catch (error) {
        console.error('Copy failed', error);
        showManualCopyMessage();
    } finally {
        document.body.removeChild(tempTextarea);
    }
}

function showCopyFeedback(button) {
    if (!button) {
        return;
    }

    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
    }

    button.classList.add('btn-success');
    button.innerHTML = '<span class="icon-markup">✅</span> Copied';

    window.setTimeout(() => {
        button.classList.remove('btn-success');
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
        }
    }, 1800);
}

function showManualCopyMessage() {
    alert('Copy failed. Please select the value manually and copy it with Cmd+C or Ctrl+C.');
}

function showLoading(show = true) {
    const messagesContainer = document.getElementById('token-messages');
    const loadingDiv = document.getElementById('token-loading');
    const successDiv = document.getElementById('token-success');
    const errorDiv = document.getElementById('token-error');

    if (!messagesContainer || !loadingDiv) {
        return;
    }

    if (show) {
        messagesContainer.style.display = 'block';
        loadingDiv.style.display = 'block';
        if (successDiv) {
            successDiv.style.display = 'none';
        }
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
        return;
    }

    loadingDiv.style.display = 'none';
}

function showSuccessMessage(message, autoHide = false) {
    const messagesContainer = document.getElementById('token-messages');
    const successDiv = document.getElementById('token-success');
    const errorDiv = document.getElementById('token-error');

    if (!messagesContainer || !successDiv) {
        return;
    }

    messagesContainer.style.display = 'block';
    successDiv.style.display = 'block';
    successDiv.textContent = message;

    if (errorDiv) {
        errorDiv.style.display = 'none';
    }

    if (autoHide) {
        window.setTimeout(() => {
            messagesContainer.style.display = 'none';
        }, 3500);
    }
}

function showErrorMessage(message) {
    const messagesContainer = document.getElementById('token-messages');
    const errorDiv = document.getElementById('token-error');
    const successDiv = document.getElementById('token-success');

    if (!messagesContainer || !errorDiv) {
        return;
    }

    messagesContainer.style.display = 'block';
    errorDiv.style.display = 'block';
    errorDiv.textContent = message;

    if (successDiv) {
        successDiv.style.display = 'none';
    }
}

function hideMessages() {
    ['token-success', 'token-error', 'token-loading'].forEach((id) => {
        const element = document.getElementById(id);
        if (element) {
            element.style.display = 'none';
        }
    });
}

function showCreatedTokenResult(clientLabel, tokenValue) {
    const container = document.getElementById('created-token-result');
    const title = document.getElementById('created-token-title');
    const description = document.getElementById('created-token-description');
    const value = document.getElementById('created-token-value');

    if (!container || !title || !description || !value) {
        return;
    }

    title.textContent = `${clientLabel} token ready`;
    description.textContent = 'Copy this token now. TYPO3 stores only a hash and cannot show the original value later.';
    value.value = tokenValue;
    container.style.display = 'block';
    container.scrollIntoView({behavior: 'smooth', block: 'center'});
}

function refreshTokens() {
    const container = document.getElementById('tokens-container');
    if (!container) {
        return Promise.resolve();
    }

    showLoading();

    return fetch(TYPO3.settings.ajaxUrls.mcp_server_get_tokens, getJsonFetchOptions())
        .then((response) => response.json())
        .then((data) => {
            showLoading(false);
            if (data.success) {
                updateTokensTable(data.tokens);
                return;
            }
            showErrorMessage(`Failed to refresh tokens: ${data.message}`);
        })
        .catch((error) => {
            showLoading(false);
            showErrorMessage(`Error refreshing tokens: ${error.message}`);
        });
}

function revokeToken(tokenId) {
    showLoading();

    const tokenIdInt = parseInt(tokenId, 10);
    if (!tokenIdInt || tokenIdInt <= 0) {
        showLoading(false);
        showErrorMessage(`Invalid token ID: ${tokenId}`);
        return;
    }

    fetch(TYPO3.settings.ajaxUrls.mcp_server_revoke_token, getJsonFetchOptions({tokenId: tokenIdInt}))
        .then((response) => response.json())
        .then((data) => {
            showLoading(false);
            if (data.success) {
                showSuccessMessage(data.message, true);
                refreshTokens();
                return;
            }
            showErrorMessage(`Failed to revoke token: ${data.message}`);
        })
        .catch((error) => {
            showLoading(false);
            showErrorMessage(`Error revoking token: ${error.message}`);
        });
}

function revokeAllTokens() {
    if (!window.confirm('Revoke all stored direct-access tokens? Connected clients will need a fresh login or token afterwards.')) {
        return;
    }

    showLoading();

    fetch(TYPO3.settings.ajaxUrls.mcp_server_revoke_all_tokens, getJsonFetchOptions())
        .then((response) => response.json())
        .then((data) => {
            showLoading(false);
            if (data.success) {
                showSuccessMessage(data.message, true);
                refreshTokens();
                return;
            }
            showErrorMessage(`Failed to revoke all tokens: ${data.message}`);
        })
        .catch((error) => {
            showLoading(false);
            showErrorMessage(`Error revoking all tokens: ${error.message}`);
        });
}

function updateTokensTable(tokens) {
    const container = document.getElementById('tokens-container');
    if (!container) {
        return;
    }

    if (!tokens || tokens.length === 0) {
        container.innerHTML = `
            <div id="no-tokens-message" class="text-center text-muted py-4">
                <div class="mb-3">
                    <span style="font-size: 2rem;">🔑</span>
                </div>
                <p>No active direct-access tokens found.</p>
                <p class="small">Create one from a token-based client panel when you need it.</p>
            </div>
        `;
        return;
    }

    const rowsHtml = tokens.map((token) => {
        const tokenId = Number.parseInt(token.uid, 10);

        return `
            <tr data-token-id="${Number.isNaN(tokenId) ? 0 : tokenId}">
                <td><strong>${escapeHtml(token.client_name)}</strong></td>
                <td><small class="text-muted">${escapeHtml(token.created)}</small></td>
                <td><small class="text-muted">${escapeHtml(token.last_used)}</small></td>
                <td><small class="text-muted">${escapeHtml(token.expires)}</small></td>
                <td><code class="small">${escapeHtml(token.token_preview)}</code></td>
                <td>
                    <button class="btn btn-sm btn-danger revoke-token-btn" data-token-id="${Number.isNaN(tokenId) ? 0 : tokenId}">
                        <span class="icon-markup">🗑️</span>
                        Revoke
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th>Expires</th>
                        <th>Stored fingerprint</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tokens-table-body">
                    ${rowsHtml}
                </tbody>
            </table>
        </div>
    `;
}

function createClientToken(button) {
    const clientType = button.dataset.createTokenClientType;
    const clientLabel = button.dataset.createTokenLabel || 'Client';
    const replaceExisting = button.dataset.replaceExisting === '1' ? '1' : '0';

    if (!clientType) {
        return;
    }

    showLoading();
    hideMessages();
    button.disabled = true;

    fetch(TYPO3.settings.ajaxUrls.mcp_server_create_token, getJsonFetchOptions({
        clientType,
        replaceExisting,
    }))
        .then((response) => response.json())
        .then((data) => {
            showLoading(false);
            button.disabled = false;

            if (!data.success) {
                showErrorMessage(data.message || `Failed to create ${clientLabel} token.`);
                return;
            }

            showCreatedTokenResult(clientLabel, data.token);
            showSuccessMessage(`${clientLabel} token created. Copy it now because TYPO3 will not show it again later.`, true);
            refreshTokens();
        })
        .catch((error) => {
            showLoading(false);
            button.disabled = false;
            showErrorMessage(`Error creating token: ${error.message}`);
        });
}

function checkEndpointStatuses() {
    document.querySelectorAll('.endpoint-status').forEach((element) => {
        const endpoint = element.getAttribute('data-endpoint');
        const checkContent = element.getAttribute('data-check-content') === 'true';
        const checkAuth = element.getAttribute('data-check-auth') === 'true';

        if (!endpoint) {
            return;
        }

        if (checkAuth) {
            checkMcpEndpointAuth(element, endpoint);
            return;
        }

        checkEndpoint(element, endpoint, checkContent);
    });
}

function checkEndpoint(element, endpoint, checkContent) {
    element.classList.add('checking');
    element.classList.remove('success', 'warning', 'error');

    fetch(endpoint, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
        },
        mode: 'cors',
        credentials: 'same-origin',
    })
        .then((response) => {
            if (!response.ok) {
                setEndpointStatus(element, 'error', `Endpoint returned ${response.status} ${response.statusText}`);
                return null;
            }

            if (!checkContent) {
                setEndpointStatus(element, 'success', 'Endpoint is reachable');
                return null;
            }

            return response.text().then((text) => {
                if (text.includes('/mcp')) {
                    setEndpointStatus(element, 'success', 'Endpoint is working correctly');
                    return;
                }
                setEndpointStatus(element, 'warning', 'Endpoint is reachable but does not mention the MCP endpoint');
            });
        })
        .catch((error) => {
            if (error.message && (error.message.includes('CORS') || error.message.includes('blocked'))) {
                setEndpointStatus(element, 'error', 'Endpoint may be blocked by CORS or security settings');
                return;
            }
            setEndpointStatus(element, 'error', `Network error: ${error.message}`);
        });
}

function setEndpointStatus(element, status, message) {
    element.classList.remove('checking', 'success', 'warning', 'error');
    element.classList.add(status);

    const tooltip = element.querySelector('.status-tooltip');
    if (tooltip) {
        tooltip.textContent = message;
    }
}

function checkMcpEndpointAuth(element, endpoint) {
    element.classList.add('checking');
    element.classList.remove('success', 'warning', 'error');

    fetch(`${endpoint}?test=auth`, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            Authorization: 'Bearer test-header-check-12345',
        },
        mode: 'cors',
        credentials: 'same-origin',
    })
        .then((response) => {
            return response.json()
                .then((data) => {
                    if (data.headers_received && data.headers_received.authorization) {
                        setEndpointStatus(element, 'success', 'MCP endpoint can receive Authorization headers');
                        const warningDiv = document.getElementById('auth-header-warning');
                        if (warningDiv) {
                            warningDiv.style.display = 'none';
                        }
                        return;
                    }

                    setEndpointStatus(element, 'error', 'MCP endpoint cannot receive Authorization headers');
                    const warningDiv = document.getElementById('auth-header-warning');
                    if (warningDiv) {
                        warningDiv.style.display = 'block';
                    }
                })
                .catch(() => {
                    if (response.status === 401) {
                        setEndpointStatus(element, 'warning', 'MCP endpoint is reachable but header status could not be confirmed');
                        return;
                    }

                    setEndpointStatus(element, 'error', `MCP endpoint returned ${response.status} ${response.statusText}`);
                });
        })
        .catch((error) => {
            if (error.message && (error.message.includes('CORS') || error.message.includes('blocked'))) {
                setEndpointStatus(element, 'error', 'MCP endpoint may be blocked by CORS or security settings');
            } else {
                setEndpointStatus(element, 'error', `Network error: ${error.message}`);
            }

            const warningDiv = document.getElementById('auth-header-warning');
            if (warningDiv) {
                warningDiv.style.display = 'block';
            }
        });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.copy-button[data-copy-target]').forEach((button) => {
        const targetId = button.getAttribute('data-copy-target');
        if (!targetId) {
            return;
        }

        button.addEventListener('click', () => copyToClipboard(targetId, button));
    });

    document.querySelectorAll('.create-token-button').forEach((button) => {
        button.addEventListener('click', () => createClientToken(button));
    });

    const refreshTokensBtn = document.getElementById('refresh-tokens-btn');
    if (refreshTokensBtn) {
        refreshTokensBtn.addEventListener('click', refreshTokens);
    }

    const revokeAllTokensBtn = document.getElementById('revoke-all-tokens-btn');
    if (revokeAllTokensBtn) {
        revokeAllTokensBtn.addEventListener('click', revokeAllTokens);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.revoke-token-btn');
        if (!button) {
            return;
        }

        const tokenId = button.getAttribute('data-token-id');
        if (!tokenId) {
            return;
        }

        if (window.confirm('Revoke this token? The connected client will lose access immediately.')) {
            revokeToken(tokenId);
        }
    });

    checkEndpointStatuses();
});
