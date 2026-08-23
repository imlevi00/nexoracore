(function () {
    'use strict';

    function parseTree(container) {
        var raw = container.getAttribute('data-options-tree');
        if (!raw) {
            return [];
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return [];
        }
    }

    function parseSelectedPath(container) {
        var raw = container.getAttribute('data-selected-path');
        if (!raw) {
            return [];
        }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed.map(function (id) {
                return parseInt(id, 10);
            }).filter(function (id) {
                return id > 0;
            }) : [];
        } catch (e) {
            return [];
        }
    }

    function findNode(nodes, id) {
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].id === id) {
                return nodes[i];
            }
            if (nodes[i].children && nodes[i].children.length) {
                var found = findNode(nodes[i].children, id);
                if (found) {
                    return found;
                }
            }
        }
        return null;
    }

    function getChildren(nodes, parentId) {
        if (!parentId) {
            return nodes;
        }
        var parent = findNode(nodes, parentId);
        return parent && parent.children ? parent.children : [];
    }

    function clearSelectEl(selectEl) {
        while (selectEl.firstChild) {
            selectEl.removeChild(selectEl.firstChild);
        }
    }

    function fillSelect(selectEl, items, placeholder, selectedId) {
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        selectEl.appendChild(empty);

        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = String(item.id);
            opt.textContent = item.label;
            if (selectedId && item.id === selectedId) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        });
    }

    function updateHidden(container, value) {
        var hidden = container.querySelector('.cf-cascade-hidden');
        if (hidden) {
            hidden.value = value ? String(value) : '';
        }
    }

    function removeLevelsFrom(container, fromIndex) {
        var levels = container.querySelectorAll('.cf-cascade-level');
        levels.forEach(function (level, idx) {
            if (idx >= fromIndex) {
                level.remove();
            }
        });
    }

    function addLevel(container, tree, parentId, levelIndex, selectedId) {
        var items = getChildren(tree, parentId);
        if (!items.length) {
            return null;
        }

        var wrap = document.createElement('div');
        wrap.className = 'cf-cascade-level mb-2';
        wrap.setAttribute('data-level', String(levelIndex));

        var label = document.createElement('label');
        label.className = 'form-label small text-muted mb-1';
        label.textContent = 'ئاستی ' + (levelIndex + 1);

        var selectEl = document.createElement('select');
        selectEl.className = 'form-select cf-cascade-select';
        selectEl.setAttribute('data-level', String(levelIndex));

        fillSelect(selectEl, items, '— هەڵبژێرە —', selectedId || null);

        wrap.appendChild(label);
        wrap.appendChild(selectEl);
        container.querySelector('.cf-cascade-levels').appendChild(wrap);

        selectEl.addEventListener('change', function () {
            onLevelChange(container, tree, levelIndex);
        });

        return selectEl;
    }

    function onLevelChange(container, tree, changedLevel) {
        var selects = container.querySelectorAll('.cf-cascade-select');
        var chosenId = 0;
        var path = [];

        selects.forEach(function (sel, idx) {
            if (idx > changedLevel) {
                return;
            }
            var val = parseInt(sel.value, 10);
            if (val > 0) {
                chosenId = val;
                path.push(val);
            }
        });

        removeLevelsFrom(container, changedLevel + 1);

        if (!chosenId) {
            updateHidden(container, '');
            return;
        }

        var children = getChildren(tree, chosenId);
        if (children.length) {
            addLevel(container, tree, chosenId, changedLevel + 1, null);
            updateHidden(container, '');
        } else {
            updateHidden(container, chosenId);
        }
    }

    function initContainer(container) {
        var tree = parseTree(container);
        var selectedPath = parseSelectedPath(container);
        var levelsWrap = container.querySelector('.cf-cascade-levels');
        if (!levelsWrap || !tree.length) {
            return;
        }

        levelsWrap.innerHTML = '';
        updateHidden(container, '');

        var parentId = null;
        var levelIndex = 0;
        var selectedIdForLevel = selectedPath.length ? selectedPath[0] : null;

        while (true) {
            var selectEl = addLevel(container, tree, parentId, levelIndex, selectedIdForLevel);
            if (!selectEl) {
                break;
            }

            var val = selectedIdForLevel;
            if (!val) {
                break;
            }

            var children = getChildren(tree, val);
            if (!children.length) {
                updateHidden(container, val);
                break;
            }

            parentId = val;
            levelIndex++;
            selectedIdForLevel = selectedPath.length > levelIndex ? selectedPath[levelIndex] : null;
            if (!selectedIdForLevel) {
                break;
            }
        }
    }

    function initAll() {
        document.querySelectorAll('.cf-cascade-field').forEach(function (container) {
            initContainer(container);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
