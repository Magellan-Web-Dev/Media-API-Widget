(function(){
  function qs(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }

  function syncTypeForRow(sel){
    var row = sel.closest('[data-maw-row]');
    if(!row) return;
    row.querySelectorAll('[data-maw-fields]').forEach(function(box){
      var isMatch = box.dataset.mawFields === sel.value;
      box.style.display = isMatch ? '' : 'none';
      box.querySelectorAll('input, select, textarea').forEach(function(el){
        el.disabled = !isMatch;
      });
    });
  }

  // Show the season/episode block only when sort mode is "Number in title";
  // hide and reset it otherwise so a stale regex isn't submitted while hidden.
  function syncSeasonEpisodeForRow(sortSel){
    var row = sortSel.closest('[data-maw-row]');
    if(!row) return;
    var block = row.querySelector('[data-maw-season-episode]');
    if(!block) return;
    block.style.display = (sortSel.value === 'number_in_title') ? '' : 'none';
    var toggle = block.querySelector('[data-maw-se-toggle]');
    if(toggle) syncSeasonEpisodeRegex(toggle);
  }

  // Show the regex input only when the "Use season/episode regex" checkbox is on.
  function syncSeasonEpisodeRegex(toggle){
    var block = toggle.closest('[data-maw-season-episode]');
    if(!block) return;
    var wrap = block.querySelector('[data-maw-se-regex-wrap]');
    if(wrap) wrap.style.display = toggle.checked ? '' : 'none';
  }

  // Full-playlist post storage warning: shown for a YouTube row only while both
  // "Load full playlist" and "Store playlist items as WordPress posts" are on.
  function syncPostsWarning(row){
    if(!row) return;
    var warning = row.querySelector('[data-maw-posts-warning]');
    if(!warning) return;
    var typeSel = row.querySelector('[data-maw-type-select]');
    var loadFull = row.querySelector('[data-maw-fields="youtube"] [data-maw-load-full]');
    var store = row.querySelector('[data-maw-store-posts]');
    var show = !!(typeSel && typeSel.value === 'youtube' && loadFull && loadFull.checked && !loadFull.disabled && store && store.checked);
    warning.style.display = show ? '' : 'none';
  }

  // Podcast season/episode regex: reveal the pattern and sort mode while on.
  function syncPodcastSeasonEpisode(toggle){
    var box = toggle.closest('[data-maw-fields]');
    if(!box) return;
    var wrap = box.querySelector('[data-maw-pod-se-wrap]');
    if(wrap) wrap.style.display = toggle.checked ? '' : 'none';
  }

  // "Load full playlist" / "Playlist item limit", in both the YouTube and the
  // podcast fields. The limit shows only while full loading is off, and both
  // hide for an embed-only podcast, which has no episode list. Hidden fields
  // stay enabled so their values are kept; a hidden limit that is not a valid
  // whole number is reset to 6 (the server's fallback too), so a field the
  // user cannot see never blocks the form from saving.
  function syncLimit(row){
    if(!row) return;
    var platformSel = row.querySelector('[data-maw-podcast-platform]');
    row.querySelectorAll('[data-maw-limit]').forEach(function(block){
      var box = block.closest('[data-maw-fields]');
      var embed = !!(box && box.dataset.mawFields === 'podcast' && platformSel && platformSel.value === 'embed');
      block.style.display = embed ? 'none' : '';
      var note = box ? box.querySelector('[data-maw-limit-embed-note]') : null;
      if(note) note.style.display = embed ? '' : 'none';
      var full = block.querySelector('[data-maw-load-full]');
      var wrap = block.querySelector('[data-maw-limit-wrap]');
      var input = block.querySelector('[data-maw-limit-input]');
      var hidden = embed || !!(full && full.checked);
      if(wrap) wrap.style.display = (full && full.checked) ? 'none' : '';
      if(hidden && input && typeof input.checkValidity === 'function' && !input.checkValidity()){
        input.value = '6';
      }
    });
  }

  function addRow(type){
    var tpl = qs('#maw-row-template');
    if(!tpl) return;
    var html = tpl.innerHTML.replace(/__TYPE__/g, type);
    const regex = new RegExp(`value="${type}"`, `g`);
    html = html.replace(regex, `value="${type}" selected`);
    var tbody = qs('#maw-table-body');
    tbody.insertAdjacentHTML('beforeend', html);
    renumber();
    var newRow = tbody.lastElementChild;
    if(newRow){
      var sel = newRow.querySelector('[data-maw-type-select]');
      if(sel) syncTypeForRow(sel);
      var sortSel = newRow.querySelector('[data-maw-sort-mode]');
      if(sortSel) syncSeasonEpisodeForRow(sortSel);
      var podToggle = newRow.querySelector('[data-maw-pod-se-toggle]');
      if(podToggle) syncPodcastSeasonEpisode(podToggle);
      syncLimit(newRow);
      syncPostsWarning(newRow);
    }
  }

  function renumber(){
    qsa('[data-maw-row]', document).forEach(function(row, idx){
      row.dataset.index = idx;
      qsa('[data-maw-name]', row).forEach(function(el){
        el.name = el.dataset.mawName.replace('__INDEX__', String(idx));
      });
    });
  }

  function addShortcodeRow(){
    var tpl = qs('#maw-shortcode-row-template');
    if(!tpl) return;
    var tbody = qs('#maw-shortcode-table-body');
    if(!tbody) return;
    tbody.insertAdjacentHTML('beforeend', tpl.innerHTML);
    renumberShortcodes();
  }

  function renumberShortcodes(){
    qsa('[data-maw-shortcode-row]', document).forEach(function(row, idx){
      row.dataset.index = idx;
      qsa('[data-maw-shortcode-name]', row).forEach(function(el){
        el.name = el.dataset.mawShortcodeName.replace('__INDEX__', String(idx));
      });
    });
  }

  document.addEventListener('click', function(e){
    var confirmLink = e.target.closest('[data-maw-confirm]');
    if(confirmLink && !window.confirm(confirmLink.dataset.mawConfirm)){
      e.preventDefault();
      return;
    }
    var add = e.target.closest('[data-maw-add]');
    if(add){
      e.preventDefault();
      addRow(add.dataset.mawAdd);
    }
    var addShortcode = e.target.closest('[data-maw-shortcode-add]');
    if(addShortcode){
      e.preventDefault();
      addShortcodeRow();
    }
    var del = e.target.closest('[data-maw-delete]');
    if(del){
      e.preventDefault();
      var row = del.closest('[data-maw-row]');
      if(row) row.remove();
      renumber();
    }
    var delShortcode = e.target.closest('[data-maw-shortcode-delete]');
    if(delShortcode){
      e.preventDefault();
      var shortcodeRow = delShortcode.closest('[data-maw-shortcode-row]');
      if(shortcodeRow) shortcodeRow.remove();
      renumberShortcodes();
    }
  });

  document.addEventListener('change', function(e){
    var sel = e.target.closest('[data-maw-type-select]');
    if(sel){
      syncTypeForRow(sel);
    }
    var sortSel = e.target.closest('[data-maw-sort-mode]');
    if(sortSel){
      syncSeasonEpisodeForRow(sortSel);
    }
    var seToggle = e.target.closest('[data-maw-se-toggle]');
    if(seToggle){
      syncSeasonEpisodeRegex(seToggle);
    }
    var podToggle = e.target.closest('[data-maw-pod-se-toggle]');
    if(podToggle){
      syncPodcastSeasonEpisode(podToggle);
    }
    if(e.target.closest('[data-maw-type-select], [data-maw-load-full], [data-maw-podcast-platform]')){
      syncLimit(e.target.closest('[data-maw-row]'));
    }
    if(e.target.closest('[data-maw-type-select], [data-maw-load-full], [data-maw-store-posts]')){
      syncPostsWarning(e.target.closest('[data-maw-row]'));
    }
  });

  // initial
  renumber();
  renumberShortcodes();
  qsa('[data-maw-type-select]').forEach(function(sel){
    syncTypeForRow(sel);
  });
  qsa('[data-maw-sort-mode]').forEach(function(sortSel){
    syncSeasonEpisodeForRow(sortSel);
  });
  qsa('[data-maw-pod-se-toggle]').forEach(function(toggle){
    syncPodcastSeasonEpisode(toggle);
  });
  qsa('[data-maw-row]').forEach(syncLimit);
  qsa('[data-maw-row]').forEach(syncPostsWarning);
})();
