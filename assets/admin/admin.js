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
})();
