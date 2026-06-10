(function () {
  const pollInterval = (window.APP_CONFIG && window.APP_CONFIG.pollIntervalMs) || 2000;

  document.querySelectorAll('[data-job-status]').forEach((panel) => {
    const jobId = panel.dataset.jobId;
    const statusUrl = panel.dataset.statusUrl;
    const statusNode = panel.querySelector('[data-field="status"]');
    const logNode = panel.querySelector('[data-field="log"]');
    const exitNode = panel.querySelector('[data-field="exit_code"]');
    const artifactNode = panel.querySelector('[data-field="artifacts"]');

    const renderArtifacts = (artifacts) => {
      if (!artifactNode) {
        return;
      }
      artifactNode.innerHTML = '';
      const entries = Object.entries(artifacts || {}).filter(([, value]) => typeof value === 'string' && value);
      if (!entries.length) {
        artifactNode.textContent = '暂无产物。';
        return;
      }
      const list = document.createElement('ul');
      list.className = 'list-reset';
      entries.forEach(([key, value]) => {
        const item = document.createElement('li');
        item.innerHTML = '<strong>' + key + '</strong><br><code>' + String(value) + '</code>';
        list.appendChild(item);
      });
      artifactNode.appendChild(list);
    };

    const tick = async () => {
      try {
        const response = await fetch(statusUrl + '?job_id=' + encodeURIComponent(jobId), { headers: { Accept: 'application/json' } });
        if (!response.ok) {
          throw new Error('无法获取任务状态');
        }
        const data = await response.json();
        if (statusNode) {
          statusNode.textContent = data.job.status;
          statusNode.className = 'status-pill status-' + data.job.status;
        }
        if (exitNode) {
          exitNode.textContent = data.job.exit_code === null ? '-' : String(data.job.exit_code);
        }
        if (logNode) {
          logNode.textContent = data.log_tail || '';
          logNode.scrollTop = logNode.scrollHeight;
        }
        renderArtifacts(data.job.artifacts || {});
        if (data.job.status === 'queued' || data.job.status === 'running') {
          window.setTimeout(tick, pollInterval);
        }
      } catch (error) {
        if (logNode) {
          logNode.textContent = String(error);
        }
      }
    };

    tick();
  });

  const proofreadRoot = document.querySelector('[data-proofread-app]');
  if (!proofreadRoot) {
    return;
  }

  const payload = JSON.parse(proofreadRoot.querySelector('script[type="application/json"]').textContent || '{}');
  if (!payload.pages || !payload.pages.length) {
    return;
  }

  const state = {
    pages: payload.pages,
    completedPages: new Set(payload.completed_pages || []),
    index: 0,
    saveUrl: payload.save_url,
    projectId: payload.project_id,
    translationPath: payload.translation_path,
  };

  const el = {
    pageIndicator: document.getElementById('page-indicator'),
    pageCounter: document.getElementById('page-counter'),
    image: document.getElementById('proofread-image'),
    ocrText: document.getElementById('ocr-text'),
    translationText: document.getElementById('translation-text'),
    proofreadText: document.getElementById('proofread-text'),
    completedToggle: document.getElementById('completed-toggle'),
    statusText: document.getElementById('proofread-status-text'),
    saveMessage: document.getElementById('save-message'),
    prevButton: document.getElementById('prev-page'),
    nextButton: document.getElementById('next-page'),
    saveCurrentButton: document.getElementById('save-current'),
    saveAllButton: document.getElementById('save-all'),
  };

  const syncCurrentDraft = () => {
    const page = state.pages[state.index];
    page.proofread_text = el.proofreadText.value;
    page.completed = !!el.completedToggle.checked;
    if (page.completed) {
      state.completedPages.add(page.page);
    } else {
      state.completedPages.delete(page.page);
    }
  };

  const render = () => {
    const page = state.pages[state.index];
    el.pageIndicator.textContent = 'P' + page.page;
    el.pageCounter.textContent = '第 ' + (state.index + 1) + ' / ' + state.pages.length + ' 页';
    el.image.src = page.image_url;
    el.ocrText.value = page.ocr_text;
    el.translationText.value = page.translation_text;
    el.proofreadText.value = page.proofread_text;
    el.completedToggle.checked = state.completedPages.has(page.page);
    el.statusText.textContent = '已校页数：' + state.completedPages.size + ' / ' + state.pages.length;
    el.prevButton.disabled = state.index === 0;
    el.nextButton.disabled = state.index === state.pages.length - 1;
  };

  const save = async (message) => {
    syncCurrentDraft();
    el.saveMessage.textContent = '保存中...';
    const response = await fetch(state.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        project_id: state.projectId,
        translation_path: state.translationPath,
        completed_pages: Array.from(state.completedPages),
        pages: state.pages.map((page) => ({ page: page.page, text: page.proofread_text || '' })),
      }),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || '保存失败');
    }
    el.saveMessage.textContent = message + '，输出文件：' + data.proofread_path;
    render();
  };

  el.proofreadText.addEventListener('input', () => {
    state.pages[state.index].proofread_text = el.proofreadText.value;
  });

  el.completedToggle.addEventListener('change', () => {
    syncCurrentDraft();
    render();
  });

  el.prevButton.addEventListener('click', () => {
    syncCurrentDraft();
    state.index -= 1;
    render();
  });

  el.nextButton.addEventListener('click', () => {
    syncCurrentDraft();
    state.index += 1;
    render();
  });

  el.saveCurrentButton.addEventListener('click', async () => {
    try {
      await save('当前页已保存');
    } catch (error) {
      el.saveMessage.textContent = String(error);
    }
  });

  el.saveAllButton.addEventListener('click', async () => {
    try {
      await save('全部页已保存');
    } catch (error) {
      el.saveMessage.textContent = String(error);
    }
  });

  render();
})();

(function () {
  const forms = document.querySelectorAll('[data-project-files-form]');
  if (!forms.length) {
    return;
  }

  const refreshDatalist = (datalist, items) => {
    if (!datalist) {
      return;
    }
    datalist.innerHTML = '';
    (items || []).forEach((value) => {
      const option = document.createElement('option');
      option.value = String(value);
      datalist.appendChild(option);
    });
  };

  forms.forEach((form) => {
    const endpoint = form.dataset.projectFilesUrl;
    const projectInput = form.querySelector('[data-project-id-input]');
    if (!endpoint || !projectInput) {
      return;
    }

    const ocrInput = form.querySelector('[data-ocr-path-input]');
    const ocrList = form.querySelector('[data-ocr-datalist]');
    const translationInput = form.querySelector('[data-translation-path-input]');
    const translationList = form.querySelector('[data-translation-datalist]');

    const load = async () => {
      const projectId = String(projectInput.value || '').trim();
      if (!projectId) {
        refreshDatalist(ocrList, []);
        refreshDatalist(translationList, []);
        return;
      }
      try {
        const response = await fetch(endpoint + '?project_id=' + encodeURIComponent(projectId), {
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.error || '获取项目文件失败');
        }
        refreshDatalist(ocrList, data.ocr_files || []);
        refreshDatalist(translationList, data.translation_files || []);

        if (ocrInput && !String(ocrInput.value || '').trim() && Array.isArray(data.ocr_files) && data.ocr_files.length) {
          ocrInput.value = data.ocr_files[0];
        }
        if (
          translationInput &&
          !String(translationInput.value || '').trim() &&
          Array.isArray(data.translation_files) &&
          data.translation_files.length
        ) {
          translationInput.value = data.translation_files[0];
        }
      } catch (error) {
        refreshDatalist(ocrList, []);
        refreshDatalist(translationList, []);
      }
    };

    projectInput.addEventListener('change', load);
    projectInput.addEventListener('blur', load);
    load();
  });
})();
