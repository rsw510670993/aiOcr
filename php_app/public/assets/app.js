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

  const kind = payload.kind || (payload.translation_path ? 'translation' : 'jp');

  const state = {
    pages: payload.pages,
    completedPages: new Set(payload.completed_pages || []),
    index: 0,
    saveUrl: payload.save_url,
    projectId: payload.project_id,
    sourceKey: kind === 'jp' ? 'ocr_path' : 'translation_path',
    sourcePath: kind === 'jp' ? payload.ocr_path : payload.translation_path,
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
    if (el.translationText) {
      el.translationText.value = page.translation_text || '';
    }
    el.proofreadText.value = page.proofread_text;
    el.completedToggle.checked = state.completedPages.has(page.page);
    el.statusText.textContent = '已校页数：' + state.completedPages.size + ' / ' + state.pages.length;
    el.prevButton.disabled = state.index === 0;
    el.nextButton.disabled = state.index === state.pages.length - 1;
  };

  const save = async (message) => {
    syncCurrentDraft();
    el.saveMessage.textContent = '保存中...';
    const requestBody = {
      project_id: state.projectId,
      completed_pages: Array.from(state.completedPages),
      pages: state.pages.map((page) => ({ page: page.page, text: page.proofread_text || '' })),
    };
    requestBody[state.sourceKey] = state.sourcePath;
    const response = await fetch(state.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(requestBody),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || '保存失败');
    }
    const outputPath = data.proofread_path || data.jp_path || data.output_path || '';
    el.saveMessage.textContent = message + (outputPath ? '，输出文件：' + outputPath : '');
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

  const basename = (value) => {
    const normalized = String(value || '').replace(/\\/g, '/');
    const parts = normalized.split('/');
    return parts[parts.length - 1] || normalized;
  };

  const refreshSelect = (select, items, preferredValue, placeholder) => {
    if (!select) {
      return;
    }
    const currentValue = preferredValue || select.dataset.currentValue || '';
    select.innerHTML = '';

    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = placeholder || '请选择';
    select.appendChild(placeholderOption);

    (items || []).forEach((value) => {
      const option = document.createElement('option');
      option.value = String(value);
      option.textContent = basename(value);
      if (String(value) === String(currentValue)) {
        option.selected = true;
      }
      select.appendChild(option);
    });

    const values = (items || []).map((item) => String(item));
    if (!values.includes(String(select.value || ''))) {
      select.value = values[0] || '';
    }
    select.dataset.currentValue = select.value || '';
  };

  forms.forEach((form) => {
    const endpoint = form.dataset.projectFilesUrl;
    const projectInput = form.querySelector('[data-project-id-input]');
    if (!endpoint || !projectInput) {
      return;
    }

    const ocrInput = form.querySelector('[data-ocr-path-input]');
    const translationInput = form.querySelector('[data-translation-path-input]');
    const ocrKey = form.dataset.ocrFilesKey || 'ocr_files';
    const translationKey = form.dataset.translationFilesKey || 'translation_files';
    let lastProjectId = String(projectInput.value || '').trim();

    const load = async () => {
      const projectId = String(projectInput.value || '').trim();
      if (!projectId) {
        refreshSelect(ocrInput, [], '', '请选择项目内 OCR 文件');
        refreshSelect(translationInput, [], '', '请选择项目内翻译文件');
        lastProjectId = '';
        return;
      }
      const projectChanged = projectId !== lastProjectId;
      try {
        const response = await fetch(endpoint + '?project_id=' + encodeURIComponent(projectId), {
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.error || '获取项目文件失败');
        }
        refreshSelect(
          ocrInput,
          data[ocrKey] || [],
          projectChanged ? '' : ocrInput && ocrInput.value,
          '请选择项目内 OCR 文件'
        );
        refreshSelect(
          translationInput,
          data[translationKey] || [],
          projectChanged ? '' : translationInput && translationInput.value,
          '请选择项目内翻译文件'
        );
        lastProjectId = projectId;
      } catch (error) {
        refreshSelect(ocrInput, [], '', '请选择项目内 OCR 文件');
        refreshSelect(translationInput, [], '', '请选择项目内翻译文件');
      }
    };

    projectInput.addEventListener('input', load);
    projectInput.addEventListener('change', load);
    projectInput.addEventListener('blur', load);
    load();
  });
})();
