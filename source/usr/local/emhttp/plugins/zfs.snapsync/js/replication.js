(() => {
const pageOptions = JSON.parse(document.getElementById('replication-options').textContent);

(function () {
  function byId(id) {
    return document.getElementById(id);
  }

  var saveForm = byId('zfsas_send_form');
  var saveButton = byId('save_send_btn');
  var saveButtonDefaultText = saveButton ? saveButton.textContent : 'Save ZFS Send Settings';
  var saveBusy = false;
  var saveSuccessTimer = null;
  var runButton = byId('run_send_now');
  var runBusy = false;
  var saveApiUrl = pageOptions[0];
    var runApiUrl = pageOptions[1];
    var queueStatusApiUrl = pageOptions[2];
    var queueStreamApiUrl = pageOptions[3];
    var queueActionApiUrl = pageOptions[4];
    var queueLogDownloadApiUrl = pageOptions[5];
  var jobsBody = byId('zfsas_send_jobs_body');
  var queueRowsBody = byId('send_queue_rows');
    var pendingDeleteStatusEl = byId('send_pending_delete_status');
    var queuePollTimer = null;
    var queueStream = null;
    var queueStreamErrorCount = 0;
    var queueStreamLastMessageAt = 0;
    var queueStreamWatchdogTimer = null;
    var queueStreamReconnectTimer = null;

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function discoverCsrfToken() {
    var globalCandidates = [window.csrf_token, window.CSRF_TOKEN, window.csrfToken];
    for (var i = 0; i < globalCandidates.length; i += 1) {
      var value = globalCandidates[i];
      if (typeof value === 'string' && value.length > 0) {
        return value;
      }
    }

    var inputSelectors = [
      'input[name="csrf_token"]',
      'input[name="csrf-token"]',
      'input[name="_csrf"]'
    ];
    for (var j = 0; j < inputSelectors.length; j += 1) {
      var csrfInput = document.querySelector(inputSelectors[j]);
      if (csrfInput && typeof csrfInput.value === 'string' && csrfInput.value.length > 0) {
        return csrfInput.value;
      }
    }

    var metaSelectors = [
      'meta[name="csrf_token"]',
      'meta[name="csrf-token"]',
      'meta[name="x-csrf-token"]'
    ];
    for (var k = 0; k < metaSelectors.length; k += 1) {
      var metaTag = document.querySelector(metaSelectors[k]);
      if (metaTag) {
        var content = metaTag.getAttribute('content');
        if (typeof content === 'string' && content.length > 0) {
          return content;
        }
      }
    }

    return '';
  }

  function extractMarkedJson(raw) {
    var beginMarker = 'ZFSAS_JSON_BEGIN';
    var endMarker = 'ZFSAS_JSON_END';
    var start = raw.indexOf(beginMarker);
    if (start === -1) {
      return null;
    }
    var contentStart = start + beginMarker.length;
    var end = raw.indexOf(endMarker, contentStart);
    if (end === -1 || end <= contentStart) {
      return null;
    }
    return raw.slice(contentStart, end).trim();
  }

  function isExpectedJsonPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      return false;
    }
    if (!Object.prototype.hasOwnProperty.call(payload, 'ok') || typeof payload.ok !== 'boolean') {
      return false;
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'errors') && !Array.isArray(payload.errors)) {
      return false;
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'notices') && !Array.isArray(payload.notices)) {
      return false;
    }
    return true;
  }

  function parsePossiblyWrappedJson(rawText) {
    var raw = String(rawText == null ? '' : rawText).trim();
    var parseError = null;

    if (raw === '') {
      throw new Error('Empty response.');
    }

    var marked = extractMarkedJson(raw);
    if (marked !== null) {
      var markedPayload = JSON.parse(marked);
      if (isExpectedJsonPayload(markedPayload)) {
        return markedPayload;
      }
      parseError = new Error('Unexpected JSON response shape.');
    }

    try {
      var directPayload = JSON.parse(raw);
      if (isExpectedJsonPayload(directPayload)) {
        return directPayload;
      }
      parseError = new Error('Unexpected JSON response shape.');
    } catch (error) {
      parseError = error;
    }

    var start = raw.indexOf('{');
    var end = raw.lastIndexOf('}');
    if (start !== -1 && end > start) {
      var candidate = raw.slice(start, end + 1);
      try {
        var candidatePayload = JSON.parse(candidate);
        if (isExpectedJsonPayload(candidatePayload)) {
          return candidatePayload;
        }
      } catch (candidateError) {
        parseError = parseError || candidateError;
      }
    }

    throw parseError || new Error('Invalid JSON response.');
  }

  function renderFeedback(errors) {
    var feedbackEl = byId('send_feedback');
    if (!feedbackEl) {
      return;
    }

    var html = '';
    if (Array.isArray(errors) && errors.length > 0) {
      html += '<div class="zfsas-send-alert zfsas-send-alert-error">';
      errors.forEach(function (message) {
        html += '<div>' + escapeHtml(message) + '</div>';
      });
      html += '</div>';
    }

    feedbackEl.innerHTML = html;
  }

  function queueBadge(label, isError) {
    return '<span class="zfsas-send-queue-badge' + (isError ? ' error' : '') + '">' + escapeHtml(label) + '</span>';
  }

    function queueProgressHtml(progress, active, visible) {
      var percent = parseInt(progress, 10);
      if (isNaN(percent) || percent < 0) {
        percent = 0;
    }
    if (percent > 100) {
      percent = 100;
    }

      return ''
        + '<div class="zfsas-send-progress' + (visible ? '' : ' idle') + (active ? ' active' : '') + '">'
        + '<div class="zfsas-send-progress-track"><div class="zfsas-send-progress-fill" style="width: ' + percent + '%;"></div></div>'
        + '<div class="zfsas-send-progress-text">' + (visible ? (percent + '%') : '&nbsp;') + '</div>'
        + '</div>';
    }

  function queueStepHtml(job) {
    var label = String(job && job.step ? job.step : '');
    if (label === '') {
      label = '-';
    }
    return '<span class="zfsas-send-step">' + escapeHtml(label) + '</span>';
  }

  function queuePathDisplay(path) {
    var value = String(path || '').replace(/\/+$/g, '');
    var segments;

    if (value === '') {
      return '';
    }

    segments = value.split('/');
    return segments.pop() || value;
  }

  function buildQueueLogDownloadUrl(jobId) {
    var normalizedJobId = String(jobId || '').trim();
    if (normalizedJobId === '') {
      return queueLogDownloadApiUrl;
    }
    return queueLogDownloadApiUrl + '?job_id=' + encodeURIComponent(normalizedJobId);
  }

    function queueRowCellsHtml(job) {
      var message = job.lastMessage || job.lastError || '';
      var rawMessage = job.rawMessage || job.lastError || job.lastMessage || '';
      var typeLabel = job.typeLabel || ((job.mode === 'manual_snapshot') ? 'Manual send' : 'Scheduled send');
      var html = '';

      html += '<td title="' + escapeHtml(job.source || '') + '"><code class="zfsas-send-queue-path">' + escapeHtml(queuePathDisplay(job.source || '')) + '</code></td>';
      html += '<td title="' + escapeHtml(job.destination || '') + '"><code class="zfsas-send-queue-path">' + escapeHtml(queuePathDisplay(job.destination || '')) + '</code></td>';
      html += '<td>' + queueBadge(typeLabel, false) + '</td>';
      html += '<td>' + queueBadge(job.stateLabel || job.state || 'Queued', job.state === 'failed') + '</td>';
      html += '<td>' + queueStepHtml(job) + '</td>';
      html += '<td>' + queueProgressHtml(job.progress, !!job.progressActive, !!job.progressVisible) + '</td>';
      html += '<td title="' + escapeHtml(rawMessage) + '"><span class="zfsas-send-queue-message">' + escapeHtml(message) + '</span></td>';
      html += '<td><div class="zfsas-send-queue-actions">';
      if (job.canCancel) {
        html += '<button type="button" class="btn zfsas-send-cancel-job" data-job-id="' + escapeHtml(job.id || '') + '">Cancel</button>';
        html += '<a class="btn" href="' + escapeHtml(job.logDownloadUrl || buildQueueLogDownloadUrl(job.id || '')) + '">Download Log</a>';
      } else if (job.canRetry) {
        html += '<a class="btn" href="' + escapeHtml(job.logDownloadUrl || buildQueueLogDownloadUrl(job.id || '')) + '">Download Log</a>';
        html += '<button type="button" class="btn zfsas-send-retry-job" data-job-id="' + escapeHtml(job.id || '') + '">Retry</button>';
        if (job.canClear) {
          html += ' <button type="button" class="btn zfsas-send-clear-job" data-job-id="' + escapeHtml(job.id || '') + '">Confirm Clear</button>';
        }
      } else if (job.canClear) {
        html += '<a class="btn" href="' + escapeHtml(job.logDownloadUrl || buildQueueLogDownloadUrl(job.id || '')) + '">Download Log</a>';
        html += '<button type="button" class="btn zfsas-send-clear-job" data-job-id="' + escapeHtml(job.id || '') + '">Confirm Clear</button>';
      } else {
        html += '<span class="zfsas-send-help">-</span>';
      }
      html += '</div></td>';

      return html;
    }

    function renderQueueJobs(jobs) {
      if (!queueRowsBody) {
        return;
      }

      if (!Array.isArray(jobs) || jobs.length === 0) {
        queueRowsBody.innerHTML = '<tr><td colspan="8" class="zfsas-send-help">No queued or recent send jobs yet.</td></tr>';
        return;
      }

      var existingRows = {};
      queueRowsBody.querySelectorAll('tr:not([data-job-id])').forEach(function (row) {
        row.remove();
      });
      queueRowsBody.querySelectorAll('tr[data-job-id]').forEach(function (row) {
        existingRows[row.getAttribute('data-job-id') || ''] = row;
      });

      jobs.forEach(function (job) {
        var jobId = String(job.id || '');
        var row = existingRows[jobId];
        var rowHtml = queueRowCellsHtml(job);
        if (!row) {
          row = document.createElement('tr');
          row.setAttribute('data-job-id', jobId);
        }
        if (row._zfsasRenderKey !== rowHtml) {
          row.innerHTML = rowHtml;
          row._zfsasRenderKey = rowHtml;
        }
        queueRowsBody.appendChild(row);
        delete existingRows[jobId];
      });

      Object.keys(existingRows).forEach(function (jobId) {
        existingRows[jobId].remove();
      });
    }

  function renderPendingDeleteCount(count) {
    if (!pendingDeleteStatusEl) {
      return;
    }
    var total = parseInt(count, 10);
    if (isNaN(total) || total < 0) {
      total = 0;
    }
    pendingDeleteStatusEl.textContent = 'Pending snapshot deletes: ' + total;
  }

    var queueRequestBusy = false;
    function handleQueuePayload(payload) {
      if (!payload || payload.ok !== true) {
        throw new Error((payload && payload.error) ? payload.error : 'Queue status failed.');
      }
      renderQueueJobs(payload.jobs || []);
      var paused = document.getElementById('zfsas-paused-schedules');
      if (!paused && queueRowsBody) {
        paused = document.createElement('div'); paused.id = 'zfsas-paused-schedules';
        queueRowsBody.closest('table').parentNode.appendChild(paused);
      }
      if (paused) {
        paused.innerHTML = (payload.pausedSchedules || []).map(function (id) {
          return '<p>Schedule ' + escapeHtml(id) + ' is paused. <button type="button" class="btn" data-resume-schedule="' + escapeHtml(id) + '">Resume</button></p>';
        }).join('');
      }
      renderPendingDeleteCount(payload.pendingDeleteCount || 0);
    }

    function loadQueueJobs() {
      if (queueRequestBusy || document.hidden) { return; }
      queueRequestBusy = true;
      requestJson(
        queueStatusApiUrl + '?_=' + Date.now(),
        function (payload) {
          queueRequestBusy = false;
          handleQueuePayload(payload);
        },
        function (error) {
          queueRequestBusy = false;
          if (!queueRowsBody) {
            return;
          }
          queueRowsBody.innerHTML = '<tr><td colspan="8" class="zfsas-send-help">Queue refresh failed: ' + escapeHtml(error.message) + '</td></tr>';
          renderPendingDeleteCount(0);
        }
      );
    }

    function stopQueueStreaming() {
      if (queueStream) {
        queueStream.close();
        queueStream = null;
      }
      if (queueStreamWatchdogTimer !== null) {
        window.clearInterval(queueStreamWatchdogTimer);
        queueStreamWatchdogTimer = null;
      }
      if (queueStreamReconnectTimer !== null) {
        window.clearTimeout(queueStreamReconnectTimer);
        queueStreamReconnectTimer = null;
      }
    }

    function startQueuePolling() {
      stopQueueStreaming();
      if (queuePollTimer !== null) {
        window.clearInterval(queuePollTimer);
      }
      loadQueueJobs();
      queuePollTimer = window.setInterval(loadQueueJobs, 5000);
    }

    function startQueueUpdates() { startQueuePolling(); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { loadQueueJobs(); } });
    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-resume-schedule]');
      if (!button) { return; }
      button.disabled = true;
      requestJsonPost(queueActionApiUrl, {action: 'resume', job_id: button.getAttribute('data-resume-schedule')},
        function (payload) { setRunStatus(payload.message, false); loadQueueJobs(); },
        function (error) { button.disabled = false; setRunStatus(error.message, true); });
    });

  function clearSaveButtonSuccessState() {
    if (!saveButton) {
      return;
    }
    if (saveSuccessTimer !== null) {
      window.clearTimeout(saveSuccessTimer);
      saveSuccessTimer = null;
    }
    saveButton.textContent = saveButtonDefaultText;
  }

  function showSaveButtonSavedState() {
    if (!saveButton) {
      return;
    }
    clearSaveButtonSuccessState();
    saveButton.textContent = 'Saved';
    saveSuccessTimer = window.setTimeout(function () {
      clearSaveButtonSuccessState();
    }, 5000);
  }

  function setSaveButtonState(isBusy) {
    saveBusy = !!isBusy;
    if (!saveButton) {
      return;
    }

    if (saveBusy) {
      clearSaveButtonSuccessState();
      saveButton.disabled = true;
      saveButton.textContent = 'Saving...';
      return;
    }

    saveButton.disabled = false;
    if (saveSuccessTimer === null) {
      saveButton.textContent = saveButtonDefaultText;
    }
  }

  function setRunStatus(message, isError) {
    var el = byId('send_run_status');
    if (!el) {
      return;
    }
    el.textContent = message;
    el.classList.toggle('error', !!isError);
  }

  function requestJsonFormPost(form, targetUrl, onSuccess, onError, onComplete) {
    var xhr = new XMLHttpRequest();
    var finished = false;

    function finalize() {
      if (finished) {
        return;
      }
      finished = true;
      if (typeof onComplete === 'function') {
        onComplete();
      }
    }

    xhr.open('POST', targetUrl, true);
    xhr.timeout = 45000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    var formData = new FormData(form);
    var params = new URLSearchParams();
    formData.forEach(function (value, key) {
      params.append(key, value);
    });
    params.append('ajax', 'save');

    var csrfToken = discoverCsrfToken();
    if (csrfToken !== '') {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      if (!params.has('csrf_token')) {
        params.append('csrf_token', csrfToken);
      }
    }

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (parseError) {
        try {
          var raw = String(xhr.responseText || '').trim();
          if (raw.charAt(0) === '<') {
            onError(new Error('Save response was wrapped by the web UI or theme. Reload the page and try again.'));
          } else {
            onError(new Error('Invalid save response.'));
          }
        } finally {
          finalize();
        }
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        try {
          onError(new Error((payload && payload.errors && payload.errors[0]) ? payload.errors[0] : ('HTTP ' + xhr.status)), payload);
        } finally {
          finalize();
        }
        return;
      }

      try {
        onSuccess(payload);
      } finally {
        finalize();
      }
    };

    xhr.onerror = function () {
      try {
        onError(new Error('Network error while saving settings.'));
      } finally {
        finalize();
      }
    };

    xhr.ontimeout = function () {
      try {
        onError(new Error('Save request timed out. Reload the page and verify whether the settings were applied.'));
      } finally {
        finalize();
      }
    };

    xhr.send(params.toString());
  }

  function requestJson(url, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        onError(new Error('HTTP ' + xhr.status));
        return;
      }

      try {
        onSuccess(parsePossiblyWrappedJson(xhr.responseText));
      } catch (error) {
        var raw = String(xhr.responseText || '').trim();
        if (raw.charAt(0) === '<') {
          onError(new Error('Request response was wrapped by the web UI or theme. Reload the page and try again.'));
        } else {
          onError(new Error('Invalid JSON response.'));
        }
      }
    };

    xhr.onerror = function () {
      onError(new Error('Network error.'));
    };

    xhr.send();
  }

  function requestJsonPost(url, bodyParams, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.timeout = url === saveApiUrl ? 45000 : 15000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    var csrfToken = discoverCsrfToken();
    if (csrfToken !== '') {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (parseError) {
        var raw = String(xhr.responseText || '').trim();
        if (raw.charAt(0) === '<') {
          onError(new Error('Request response was wrapped by the web UI or theme. Reload the page and try again.'));
        } else {
          onError(new Error('Invalid JSON response.'));
        }
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        onError(new Error((payload && payload.error) ? payload.error : ('HTTP ' + xhr.status)), payload);
        return;
      }

      onSuccess(payload);
    };

    xhr.onerror = function () {
      onError(new Error('Network error.'));
    };

    xhr.ontimeout = function () {
      onError(new Error('Request timed out.'));
    };

    var params = new URLSearchParams();
    Object.keys(bodyParams || {}).forEach(function (key) {
      params.append(key, bodyParams[key]);
    });
    if (csrfToken !== '') {
      params.append('csrf_token', csrfToken);
    }

    xhr.send(params.toString());
  }

  if (saveButton && saveButton.getAttribute('data-show-saved') === '1') {
    showSaveButtonSavedState();
    saveButton.removeAttribute('data-show-saved');
  }

  var pendingRunCommand = null;
  if (runButton) {
    runButton.addEventListener('click', function () {
      if (runBusy) {
        return;
      }
      runBusy = true;
      runButton.disabled = true;
      pendingRunCommand = pendingRunCommand || ('manual-send-' + Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join(''));
      setRunStatus('Starting manual ZFS send run...', false);

      requestJsonPost(
        runApiUrl,
        {command_id: pendingRunCommand},
        function (data) {
          runBusy = false;
          runButton.disabled = false;
          if (!data || data.ok !== true) {
            setRunStatus('Manual ZFS send start failed: Unexpected response.', true);
            return;
          }
          pendingRunCommand = null;
          setRunStatus((typeof data.message === 'string' && data.message.length > 0) ? data.message : 'Manual ZFS send started.', false);
          if(data.recoveryRequired?.length===1 && window.ZfsasRecovery){const recovery=data.recoveryRequired[0];ZfsasRecovery.open(recovery.runId,recovery.scheduleId,runButton);}
          loadQueueJobs();
        },
        function (error) {
          runBusy = false;
          runButton.disabled = false;
          setRunStatus('Manual ZFS send start failed: ' + error.message, true);
        }
      );
    });
  }

  if (queueRowsBody) {
    queueRowsBody.addEventListener('click', function (event) {
      var retryButton = event.target.closest('.zfsas-send-retry-job');
      var clearButton = event.target.closest('.zfsas-send-clear-job');
      var cancelButton = event.target.closest('.zfsas-send-cancel-job');
      var button = retryButton || clearButton || cancelButton;
      var action = retryButton ? 'retry' : (clearButton ? 'clear_failed' : (cancelButton ? 'cancel' : ''));
      var successMessage = retryButton ? 'Send job queued for retry.' : (clearButton ? 'Failed send job cleared from the queue.' : 'Send job canceled. It will stay in the queue until you clear it.');

      if (!button || action === '') {
        return;
      }

      var jobId = button.getAttribute('data-job-id') || '';
      if (!jobId) {
        return;
      }

      if (action === 'cancel' && !window.confirm('Cancel the whole current run and pause its schedule? All child transfers will stop. Use Resume to allow future runs.')) {
        return;
      }

      button.disabled = true;
      requestJsonPost(
        queueActionApiUrl,
        {action: action, job_id: jobId},
        function (payload) {
          setRunStatus(payload && payload.message ? payload.message : successMessage, false);
          loadQueueJobs();
        },
        function (error, payload) {
          button.disabled = false;
          setRunStatus((payload && payload.error) ? payload.error : error.message, true);
        }
      );
    });
  }

    if (queueRowsBody) startQueueUpdates();
  })();

})();
