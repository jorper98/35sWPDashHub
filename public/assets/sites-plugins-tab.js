(function () {
    "use strict";

    var jsonEl = document.getElementById("hub-fleet-plugins-json");
    var table = document.getElementById("hub-fleet-plugins-table");
    var searchInput = document.getElementById("hub-fleet-plugin-search");
    var siteFilter = document.getElementById("hub-fleet-plugin-site-filter");
    var statusFilter = document.getElementById("hub-fleet-plugin-status-filter");
    var dialog = document.getElementById("hub-fleet-plugin-dialog");
    var dialogTitle = document.getElementById("hub-fleet-dialog-title");
    var dialogSub = document.getElementById("hub-fleet-dialog-sub");
    var dialogBody = document.getElementById("hub-fleet-dialog-body");

    if (!jsonEl || !table || !dialog || !dialogTitle || !dialogBody) {
        return;
    }

    var rows;
    try {
        rows = JSON.parse(jsonEl.textContent || "[]");
    } catch (e) {
        rows = [];
    }
    if (!Array.isArray(rows)) {
        rows = [];
    }

    function readFilterFromUrl() {
        var params = new URLSearchParams(window.location.search);
        return {
            search: params.get("plugin_search") || sessionStorage.getItem("hub_plugin_search") || "",
            site: params.get("plugin_site") || sessionStorage.getItem("hub_plugin_site") || "all",
            status: params.get("plugin_status") || sessionStorage.getItem("hub_plugin_status") || "all",
        };
    }

    function writeFilterToUrl(q, siteId, mode) {
        var url = new URL(window.location.href);
        if (q) {
            url.searchParams.set("plugin_search", q);
            sessionStorage.setItem("hub_plugin_search", q);
        } else {
            url.searchParams.delete("plugin_search");
            sessionStorage.removeItem("hub_plugin_search");
        }
        if (siteId && siteId !== "all") {
            url.searchParams.set("plugin_site", siteId);
            sessionStorage.setItem("hub_plugin_site", siteId);
        } else {
            url.searchParams.delete("plugin_site");
            sessionStorage.removeItem("hub_plugin_site");
        }
        if (mode && mode !== "all") {
            url.searchParams.set("plugin_status", mode);
            sessionStorage.setItem("hub_plugin_status", mode);
        } else {
            url.searchParams.delete("plugin_status");
            sessionStorage.removeItem("hub_plugin_status");
        }
        history.replaceState(null, "", url.toString());
    }

    function restoreFiltersFromUrl() {
        var saved = readFilterFromUrl();
        if (searchInput && saved.search) {
            searchInput.value = saved.search;
        }
        if (siteFilter && saved.site && saved.site !== "all") {
            siteFilter.value = saved.site;
        }
        if (statusFilter && saved.status && saved.status !== "all") {
            statusFilter.value = saved.status;
        }
    }

    restoreFiltersFromUrl();

    function rowMatchesSearch(row, q) {
        if (!q) {
            return true;
        }
        var blob = row.getAttribute("data-search") || "";
        return blob.indexOf(q) !== -1;
    }

    function rowMatchesStatus(row, mode) {
        if (mode === "active") {
            return row.getAttribute("data-fleet-has-active") === "1";
        }
        if (mode === "inactive") {
            return row.getAttribute("data-fleet-has-inactive") === "1";
        }
        return true;
    }

    function getStatusFilterMode() {
        return statusFilter && statusFilter.value ? statusFilter.value : "all";
    }

    function getSiteFilterMode() {
        return siteFilter && siteFilter.value ? siteFilter.value : "all";
    }

    function rowMatchesSite(rowObj, siteId) {
        if (siteId === "all") {
            return true;
        }
        if (!rowObj || typeof rowObj !== "object" || !Array.isArray(rowObj.sites)) {
            return false;
        }
        var sid = parseInt(siteId, 10);
        for (var i = 0; i < rowObj.sites.length; i++) {
            if (rowObj.sites[i] && typeof rowObj.sites[i] === "object" && rowObj.sites[i].site_id === sid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sites shown in the count, modal, and bulk actions for the current "Show" filter (not search).
     * @param {unknown} sites
     * @param {string} mode
     * @returns {Array<Record<string, unknown>>}
     */
    function filterSitesByMode(sites, mode) {
        if (!Array.isArray(sites)) {
            return [];
        }
        if (mode === "active") {
            return sites.filter(function (s) {
                return s && typeof s === "object" && !!s.active;
            });
        }
        if (mode === "inactive") {
            return sites.filter(function (s) {
                return s && typeof s === "object" && !s.active;
            });
        }
        return sites.slice();
    }

    function filteredSitesForDataRow(rowObj) {
        if (!rowObj || typeof rowObj !== "object") {
            return [];
        }
        var sites = rowObj.sites;
        return filterSitesByMode(sites, getStatusFilterMode());
    }

    function updateFleetSiteCountButtons() {
        var mode = getStatusFilterMode();
        var siteId = getSiteFilterMode();
        table.querySelectorAll(".hub-fleet-plugin-row").forEach(function (tr) {
            var btn = tr.querySelector(".hub-fleet-sites-btn");
            if (!btn) {
                return;
            }
            var idx = parseInt(btn.getAttribute("data-fleet-row") || "-1", 10);
            if (isNaN(idx) || idx < 0 || !rows[idx]) {
                return;
            }
            var filtered = filterSitesByMode(rows[idx].sites, mode);
            if (siteId !== "all") {
                var sid = parseInt(siteId, 10);
                filtered = filtered.filter(function (s) {
                    return s && typeof s === "object" && s.site_id === sid;
                });
            }
            var activeCount = 0;
            var inactiveCount = 0;
            filtered.forEach(function (s) {
                if (s && typeof s === "object") {
                    if (s.active) {
                        activeCount++;
                    } else {
                        inactiveCount++;
                    }
                }
            });
            btn.textContent = String(activeCount) + " / " + String(inactiveCount);
        });
    }

    function isHubCompanionRow(row) {
        var file = row && row.file != null ? String(row.file) : "";
        return file.toLowerCase() === "s35-wp-hub/s35-wp-hub.php";
    }

    function updateFleetBulkButtonsState() {
        var siteId = getSiteFilterMode();
        table.querySelectorAll(".hub-fleet-plugin-row").forEach(function (tr) {
            var sitesBtn = tr.querySelector(".hub-fleet-sites-btn");
            var deactBtn = tr.querySelector(".hub-fleet-deactivate-all-btn");
            var delBtn = tr.querySelector(".hub-fleet-delete-all-btn");
            if (!sitesBtn) {
                return;
            }
            var idx = parseInt(sitesBtn.getAttribute("data-fleet-row") || "-1", 10);
            if (isNaN(idx) || idx < 0 || !rows[idx]) {
                return;
            }
            var row = rows[idx];
            if (isHubCompanionRow(row)) {
                return;
            }
            if (siteId !== "all") {
                var sid = parseInt(siteId, 10);
                var hasSite = false;
                if (Array.isArray(row.sites)) {
                    for (var i = 0; i < row.sites.length; i++) {
                        if (row.sites[i] && row.sites[i].site_id === sid) {
                            hasSite = true;
                            break;
                        }
                    }
                }
                if (!hasSite) {
                    if (delBtn) {
                        delBtn.disabled = true;
                        delBtn.title = "Plugin not installed on selected site.";
                    }
                    if (deactBtn) {
                        deactBtn.disabled = true;
                        deactBtn.title = "Plugin not installed on selected site.";
                    }
                    return;
                }
            }
            var delIds = siteIdsFromFilteredSitesForDelete(row);
            var deactIds = siteIdsFromFilteredSitesForDeactivate(row);
            if (delBtn) {
                delBtn.disabled = delIds.length === 0;
                delBtn.title =
                    delIds.length === 0
                        ? "No sites in this table filter — change Show or clear search."
                        : "";
            }
            if (deactBtn) {
                deactBtn.disabled = deactIds.length === 0;
                deactBtn.title =
                    deactIds.length === 0
                        ? "No active installations in this view — switch to All or Active, or clear search."
                        : "";
            }
        });
    }

    function applyFleetPluginFilters() {
        var raw = searchInput ? (searchInput.value || "").trim() : "";
        var q = raw.toLowerCase();
        var siteId = getSiteFilterMode();
        var mode = getStatusFilterMode();
        var anyVisible = false;
        table.querySelectorAll(".hub-fleet-plugin-row").forEach(function (tr) {
            var idx = parseInt(tr.getAttribute("data-fleet-row-index") || "-1", 10);
            var rowObj = isNaN(idx) || idx < 0 ? null : rows[idx];
            var matchesSite = rowMatchesSite(rowObj, siteId);
            var matchesStatus = rowMatchesStatus(tr, mode);
            var matchesSearch = rowMatchesSearch(tr, q);
            var ok = matchesSite && matchesStatus && matchesSearch;
            tr.classList.toggle("is-hidden", !ok);
            if (ok) anyVisible = true;
        });
        updateFleetSiteCountButtons();
        updateFleetBulkButtonsState();
        writeFilterToUrl(raw, siteId, mode);
    }

    if (searchInput) {
        searchInput.addEventListener("input", applyFleetPluginFilters);
    }
    if (siteFilter) {
        siteFilter.addEventListener("change", applyFleetPluginFilters);
    }
    if (statusFilter) {
        statusFilter.addEventListener("change", applyFleetPluginFilters);
    }

    applyFleetPluginFilters();

    function esc(s) {
        var d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function isHttpUrl(s) {
        try {
            var u = new URL(s);
            return u.protocol === "http:" || u.protocol === "https:";
        } catch (e) {
            return false;
        }
    }

    function fleetPluginInfoLinksHtml(row) {
        var wp = row && row.wp_org_url != null ? String(row.wp_org_url).trim() : "";
        var authorUri = row && row.author_uri != null ? String(row.author_uri).trim() : "";
        var author = row && row.author != null ? String(row.author).trim() : "";
        if (!isHttpUrl(authorUri)) {
            authorUri = "";
        }
        var parts = [];
        if (wp !== "") {
            parts.push(
                '<a href="' +
                    esc(wp) +
                    '" target="_blank" rel="noopener noreferrer" class="hub-fleet-plugin-info-link" title="Plugin directory slug from folder name; may not exist for non–WordPress.org plugins">WordPress.org</a>'
            );
        }
        if (authorUri !== "" && authorUri !== wp) {
            if (parts.length) {
                parts.push('<span class="hub-fleet-plugin-links__sep" aria-hidden="true"> · </span>');
            }
            var lab = author !== "" ? esc(author) : "Author";
            parts.push(
                '<a href="' +
                    esc(authorUri) +
                    '" target="_blank" rel="noopener noreferrer" class="hub-fleet-plugin-info-link">' +
                    lab +
                    "</a>"
            );
        }
        if (parts.length === 0) {
            return "";
        }
        return '<div class="hub-fleet-plugin-links">' + parts.join("") + "</div>";
    }

    function wpAdminUrl(siteUrl) {
        var u = siteUrl != null ? String(siteUrl).trim() : "";
        if (u === "") {
            return "";
        }
        return u.replace(/\/+$/, "") + "/wp-admin/";
    }

    function showFleetProgress(title, show) {
        var overlay = document.getElementById("hub-progress-overlay");
        var titleEl = document.getElementById("hub-progress-title");
        var statusEl = document.getElementById("hub-progress-status");
        var logEl = document.getElementById("hub-progress-log");
        var closeBtn = document.getElementById("hub-progress-close");
        var barEl = document.getElementById("hub-progress-bar");
        if (!overlay) {
            return;
        }
        if (show) {
            overlay.hidden = false;
            overlay.setAttribute("aria-hidden", "false");
            document.documentElement.classList.add("hub-progress-active");
            if (titleEl) {
                titleEl.textContent = title || "Working…";
            }
            if (statusEl) {
                statusEl.textContent = "Sending request…";
            }
            if (logEl) {
                logEl.innerHTML = "";
            }
            if (closeBtn) {
                closeBtn.hidden = true;
            }
            if (barEl) {
                barEl.style.width = "100%";
                barEl.classList.add("hub-progress-bar--indeterminate");
            }
        } else {
            overlay.hidden = true;
            overlay.setAttribute("aria-hidden", "true");
            document.documentElement.classList.remove("hub-progress-active");
            if (barEl) {
                barEl.classList.remove("hub-progress-bar--indeterminate");
                barEl.style.width = "0%";
            }
        }
    }

    function postFleetPluginAction(action, confirmMsg, progressTitle, pluginFile, siteIds) {
        if (!pluginFile || !siteIds || !siteIds.length) {
            return;
        }
        var body = document.body;
        if (!body || !body.dataset.csrf) {
            return;
        }
        if (!window.confirm(confirmMsg)) {
            return;
        }

        showFleetProgress(progressTitle, true);
        var statusEl = document.getElementById("hub-progress-status");
        var logEl = document.getElementById("hub-progress-log");
        var closeBtn = document.getElementById("hub-progress-close");
        var barEl = document.getElementById("hub-progress-bar");

        var fd = new FormData();
        fd.append("csrf", body.dataset.csrf);
        fd.append("action", action);
        fd.append("ajax", "1");
        fd.append("confirm", "1");
        fd.append("plugin_file", pluginFile);
        fd.append("site_ids", siteIds.join(","));

        fetch("index.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (e) {
                        throw new Error(
                            r.ok
                                ? "Server returned non-JSON (check PHP errors / logs)."
                                : "Request failed (" + r.status + ")."
                        );
                    }
                    if (!r.ok) {
                        throw new Error((data && data.error) || "Request failed (" + r.status + ")");
                    }
                    if (data === null || typeof data !== "object") {
                        throw new Error("Empty or invalid JSON response.");
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (barEl) {
                    barEl.classList.remove("hub-progress-bar--indeterminate");
                }
                if (statusEl) {
                    statusEl.textContent = data.ok ? "Finished. Reloading…" : "Some sites reported failure. Reloading…";
                }
                if (data.results && Array.isArray(data.results) && logEl) {
                    data.results.forEach(function (r) {
                        if (!r || typeof r !== "object") {
                            return;
                        }
                        var p = document.createElement("p");
                        p.className = "hub-progress-log-line";
                        var lab = r.label != null ? String(r.label) : "Site " + String(r.site_id || "");
                        var mark = r.ok ? "✓" : "✗";
                        p.textContent = mark + " " + lab + " — " + (r.message != null ? String(r.message) : "");
                        logEl.appendChild(p);
                    });
                }
                setTimeout(function () {
                    window.location.reload();
                }, 3000);
            })
            .catch(function (err) {
                if (barEl) {
                    barEl.classList.remove("hub-progress-bar--indeterminate");
                }
                if (statusEl) {
                    statusEl.textContent = (err.message || "Error") + ". Reloading…";
                }
                setTimeout(function () {
                    window.location.reload();
                }, 3000);
            });
    }

    function runDeactivate(pluginFile, siteIds) {
        postFleetPluginAction(
            "deactivate_remote_plugin",
            "Deactivate plugin \"" +
                pluginFile +
                "\" on " +
                siteIds.length +
                " selected site(s)? It will stop running but files stay installed.",
            "Deactivating plugin",
            pluginFile,
            siteIds
        );
    }

    function runActivate(pluginFile, siteIds) {
        postFleetPluginAction(
            "activate_remote_plugin",
            "Activate plugin \"" +
                pluginFile +
                "\" on " +
                siteIds.length +
                " selected site(s)?",
            "Activating plugin",
            pluginFile,
            siteIds
        );
    }

    function runDelete(pluginFile, siteIds) {
        postFleetPluginAction(
            "delete_remote_plugin",
            "Permanently delete plugin \"" +
                pluginFile +
                "\" from " +
                siteIds.length +
                " selected site(s)? This removes files on the live WordPress installation and cannot be undone.",
            "Deleting plugin",
            pluginFile,
            siteIds
        );
    }

    function openModal(index) {
        var row = rows[index];
        if (!row || typeof row !== "object") {
            return;
        }
        var name = row.name != null ? String(row.name) : "";
        var file = row.file != null ? String(row.file) : "";
        if (dialogSub) {
            var subHtml = fleetPluginInfoLinksHtml(row);
            dialogSub.innerHTML = subHtml;
            dialogSub.hidden = subHtml === "";
        }
        var mode = getStatusFilterMode();
        var modeHint = "";
        if (mode === "active") {
            modeHint =
                '<p class="muted small">Showing only sites where this plugin is <strong>active</strong> (matches the table filter).</p>';
        } else if (mode === "inactive") {
            modeHint =
                '<p class="muted small">Showing only sites where this plugin is <strong>inactive</strong> (matches the table filter).</p>';
        }
        dialogTitle.textContent = name || file || "Plugin";
        var sites = filteredSitesForDataRow(row);
        var isHubSelf = file.toLowerCase() === "s35-wp-hub/s35-wp-hub.php";
        if (!Array.isArray(sites) || sites.length === 0) {
            dialogBody.innerHTML =
                modeHint +
                "<p class=\"muted\">No sites in this view. Try another filter or clear search.</p>";
        } else {
            var inactiveCount = 0;
            var activeCount = 0;
            var allSiteIds = [];
            sites.forEach(function (s) {
                if (!s || typeof s !== "object") {
                    return;
                }
                var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
                if (sid > 0) {
                    allSiteIds.push(sid);
                    if (s.active) {
                        activeCount++;
                    } else {
                        inactiveCount++;
                    }
                }
            });

            var bulkToolbar = "";
            if (!isHubSelf && file !== "") {
                var deactAllLabel = "Deactivate all (" + activeCount + ")";
                var activateAllLabel = "Activate all (" + inactiveCount + ")";
                var deleteAllLabel = "Delete from all (" + allSiteIds.length + ")";
                var deactAllDisabled = activeCount === 0 ? " disabled" : "";
                var activateAllDisabled = inactiveCount === 0 ? " disabled" : "";
                var deleteAllDisabled = allSiteIds.length === 0 ? " disabled" : "";
                bulkToolbar =
                    '<div class="hub-modal-bulk-toolbar">' +
                    '<button type="button" class="btn small hub-modal-deactivate-all-btn"' + deactAllDisabled + '>' + esc(deactAllLabel) + '</button>' +
                    '<button type="button" class="btn small hub-modal-activate-all-btn"' + activateAllDisabled + '>' + esc(activateAllLabel) + '</button>' +
                    '<button type="button" class="btn small danger hub-modal-delete-all-btn"' + deleteAllDisabled + '>' + esc(deleteAllLabel) + '</button>' +
                    "</div>";
            }

            var html =
                modeHint +
                bulkToolbar +
                '<table class="grid hub-fleet-modal-table">' +
                "<thead><tr>" +
                "<th>Version</th>" +
                "<th>Site label</th>" +
                "<th>Status</th>" +
                "<th class=\"actions\">Deactivate</th>" +
                "<th class=\"actions\">Remove</th>" +
                "</tr></thead><tbody>";
            sites.forEach(function (s) {
                if (!s || typeof s !== "object") {
                    return;
                }
                var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
                var label = s.label != null ? String(s.label) : "";
                var url = s.site_url != null ? String(s.site_url) : "";
                var ver = s.version != null ? String(s.version) : "";
                var active = !!s.active;
                var verDisp = ver !== "" ? ver : "—";
                var stateLabel = active ? "Active" : "Inactive";
                var stateBtnLabel = active ? "Active" : "Inactive";
                var stateBtnClass = active ? "btn small" : "btn small danger";
                var admin = wpAdminUrl(url);
                var labelCell =
                    admin !== ""
                        ? '<a href="' +
                          esc(admin) +
                          '" target="_blank" rel="noopener noreferrer">' +
                          esc(label || url || "Site") +
                          "</a>"
                        : esc(label || url || "—");

                var statusBtn;
                if (sid) {
                    statusBtn =
                        '<button type="button" class="' +
                        stateBtnClass +
                        ' hub-fleet-toggle-status-btn" data-site-id="' +
                        sid +
                        '" data-active="' +
                        (active ? "1" : "0") +
                        '">' +
                        esc(stateBtnLabel) +
                        "</button>";
                } else {
                    statusBtn = '<span class="muted small">—</span>';
                }

                var deactBtn;
                if (isHubSelf) {
                    deactBtn =
                        '<span class="muted small" title="The companion plugin must stay active for the dashboard.">—</span>';
                } else if (sid && active) {
                    deactBtn =
                        '<button type="button" class="btn small hub-fleet-deactivate-one-btn" data-site-id="' +
                        sid +
                        '">This site</button>';
                } else if (sid) {
                    deactBtn = '<span class="muted small">Off</span>';
                } else {
                    deactBtn = '<span class="muted small">—</span>';
                }

                var delBtn;
                if (isHubSelf) {
                    delBtn =
                        '<span class="muted small" title="The companion plugin cannot be removed from the dashboard.">—</span>';
                } else if (sid) {
                    delBtn =
                        '<button type="button" class="btn small danger hub-fleet-delete-one-btn" data-site-id="' +
                        sid +
                        '">This site</button>';
                } else {
                    delBtn = '<span class="muted small">—</span>';
                }

                html +=
                    "<tr>" +
                    "<td>" +
                    esc(verDisp) +
                    "</td>" +
                    "<td>" +
                    labelCell +
                    "</td>" +
                    "<td>" +
                    statusBtn +
                    "</td>" +
                    "<td class=\"actions\">" +
                    deactBtn +
                    "</td>" +
                    "<td class=\"actions\">" +
                    delBtn +
                    "</td>" +
                    "</tr>";
            });
            html += "</tbody></table>";
            dialogBody.innerHTML = html;
            if (!isHubSelf && file !== "") {
                dialogBody.querySelectorAll(".hub-fleet-deactivate-one-btn").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        var sid = parseInt(btn.getAttribute("data-site-id") || "0", 10);
                        if (!sid) {
                            return;
                        }
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        runDeactivate(file, [sid]);
                    });
                });
                dialogBody.querySelectorAll(".hub-fleet-delete-one-btn").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        var sid = parseInt(btn.getAttribute("data-site-id") || "0", 10);
                        if (!sid) {
                            return;
                        }
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        runDelete(file, [sid]);
                    });
                });
                dialogBody.querySelectorAll(".hub-fleet-toggle-status-btn").forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        var sid = parseInt(btn.getAttribute("data-site-id") || "0", 10);
                        if (!sid) {
                            return;
                        }
                        var isActive = btn.getAttribute("data-active") === "1";
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        if (isActive) {
                            runDeactivate(file, [sid]);
                        } else {
                            runActivate(file, [sid]);
                        }
                    });
                });
                var deactAllBtn = dialogBody.querySelector(".hub-modal-deactivate-all-btn");
                if (deactAllBtn) {
                    deactAllBtn.addEventListener("click", function () {
                        if (!file) {
                            return;
                        }
                        var activeIds = [];
                        sites.forEach(function (s) {
                            if (!s || typeof s !== "object" || !s.active) {
                                return;
                            }
                            var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
                            if (sid > 0) {
                                activeIds.push(sid);
                            }
                        });
                        if (activeIds.length === 0) {
                            return;
                        }
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        runDeactivate(file, activeIds);
                    });
                }
                var activateAllBtn = dialogBody.querySelector(".hub-modal-activate-all-btn");
                if (activateAllBtn) {
                    activateAllBtn.addEventListener("click", function () {
                        if (!file) {
                            return;
                        }
                        var inactiveIds = [];
                        sites.forEach(function (s) {
                            if (!s || typeof s !== "object" || s.active) {
                                return;
                            }
                            var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
                            if (sid > 0) {
                                inactiveIds.push(sid);
                            }
                        });
                        if (inactiveIds.length === 0) {
                            return;
                        }
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        runActivate(file, inactiveIds);
                    });
                }
                var deleteAllBtn = dialogBody.querySelector(".hub-modal-delete-all-btn");
                if (deleteAllBtn) {
                    deleteAllBtn.addEventListener("click", function () {
                        if (!file) {
                            return;
                        }
                        if (allSiteIds.length === 0) {
                            return;
                        }
                        if (typeof dialog.close === "function") {
                            dialog.close();
                        }
                        runDelete(file, allSiteIds);
                    });
                }
            }
        }
        if (typeof dialog.showModal === "function") {
            dialog.showModal();
        }
    }

    table.querySelectorAll(".hub-fleet-sites-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var idx = parseInt(btn.getAttribute("data-fleet-row") || "-1", 10);
            if (!isNaN(idx) && idx >= 0) {
                openModal(idx);
            }
        });
    });

    function siteIdsFromFilteredSitesForDelete(row) {
        var sites = filteredSitesForDataRow(row);
        var ids = [];
        sites.forEach(function (s) {
            if (!s || typeof s !== "object") {
                return;
            }
            var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
            if (sid > 0) {
                ids.push(sid);
            }
        });
        return ids;
    }

    function siteIdsFromFilteredSitesForDeactivate(row) {
        var sites = filteredSitesForDataRow(row);
        var ids = [];
        sites.forEach(function (s) {
            if (!s || typeof s !== "object" || !s.active) {
                return;
            }
            var sid = s.site_id != null ? parseInt(String(s.site_id), 10) : 0;
            if (sid > 0) {
                ids.push(sid);
            }
        });
        return ids;
    }

    table.querySelectorAll(".hub-fleet-delete-all-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var idx = parseInt(btn.getAttribute("data-fleet-row") || "-1", 10);
            if (isNaN(idx) || idx < 0) {
                return;
            }
            var row = rows[idx];
            if (!row || typeof row !== "object") {
                return;
            }
            var ids = siteIdsFromFilteredSitesForDelete(row);
            if (ids.length === 0) {
                return;
            }
            runDelete(row.file != null ? String(row.file) : "", ids);
        });
    });

    table.querySelectorAll(".hub-fleet-deactivate-all-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var idx = parseInt(btn.getAttribute("data-fleet-row") || "-1", 10);
            if (isNaN(idx) || idx < 0) {
                return;
            }
            var row = rows[idx];
            if (!row || typeof row !== "object") {
                return;
            }
            var ids = siteIdsFromFilteredSitesForDeactivate(row);
            if (ids.length === 0) {
                return;
            }
            runDeactivate(row.file != null ? String(row.file) : "", ids);
        });
    });

    dialog.querySelectorAll(".hub-dialog-close").forEach(function (btn) {
        btn.addEventListener("click", function () {
            dialog.close();
        });
    });

    dialog.addEventListener("click", function (ev) {
        if (ev.target === dialog) {
            dialog.close();
        }
    });
})();
