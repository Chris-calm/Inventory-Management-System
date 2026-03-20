const body = document.querySelector("body"),
      sidebar = document.querySelector(".sidebar"),
      toggle = document.querySelector(".toggle"),
      searchBtn = document.querySelector(".search-box"),
      modeSwitch = document.querySelector(".toggle-switch"),
      modeText = document.querySelector(".mode-text");

const STORAGE_KEYS = {
  theme: "ims_theme",
  sidebar: "ims_sidebar",
};

function applyTheme(isDark) {
  const root = document.documentElement;
  if (root) {
    root.classList.toggle("dark", Boolean(isDark));
  }
  if (body) {
    body.classList.toggle("dark", Boolean(isDark));
  }
  if (modeText) {
    const isNowDark = (root && root.classList.contains("dark")) || (body && body.classList.contains("dark"));
    modeText.innerText = isNowDark ? "Light Mode" : "Dark Mode";
  }
}

function applySidebar(isClosed) {
  if (!sidebar) return;
  sidebar.classList.toggle("close", Boolean(isClosed));
}

// Restore persisted preferences
try {
  const savedTheme = localStorage.getItem(STORAGE_KEYS.theme);
  const savedSidebar = localStorage.getItem(STORAGE_KEYS.sidebar);
  applyTheme(savedTheme === "dark");
  // default is closed to match your HTML
  applySidebar(savedSidebar ? savedSidebar === "closed" : true);
} catch (e) {
  // ignore storage errors
}

if (toggle && sidebar) {
  toggle.addEventListener("click", () => {
    sidebar.classList.toggle("close");
    try {
      localStorage.setItem(STORAGE_KEYS.sidebar, sidebar.classList.contains("close") ? "closed" : "open");
    } catch (e) {}
  });
}

if (searchBtn && sidebar) {
  searchBtn.addEventListener("click", () => {
    sidebar.classList.remove("close");
    try {
      localStorage.setItem(STORAGE_KEYS.sidebar, "open");
    } catch (e) {}
  });
}

if (modeSwitch && body) {
  modeSwitch.addEventListener("click", () => {
    const root = document.documentElement;
    const isDark = (root && root.classList.contains("dark")) || body.classList.contains("dark");
    const nextDark = !isDark;
    applyTheme(nextDark);
    try {
      localStorage.setItem(STORAGE_KEYS.theme, nextDark ? "dark" : "light");
    } catch (e) {}
  });
}

// Sidebar dropdowns (sub-modules)
try {
  const dropdownToggles = document.querySelectorAll(".nav-dropdown .dropdown-toggle");
  dropdownToggles.forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      const li = el.closest(".nav-dropdown");
      if (!li) return;
      if (sidebar && sidebar.classList.contains("close")) {
        sidebar.classList.remove("close");
        try {
          localStorage.setItem(STORAGE_KEYS.sidebar, "open");
        } catch (err) {}
      }
      li.classList.toggle("open");
    });
  });
} catch (e) {
  // ignore
}

try {
  const mmList = document.querySelector("#mm-list");
  const mmOrder = document.querySelector("#mm-module-order");
  const mmForm = document.querySelector("#mm-save-form");

  const updateOrder = () => {
    if (!mmList || !mmOrder) return;
    const keys = Array.from(mmList.querySelectorAll(".mm-item"))
      .map((el) => el.getAttribute("data-module-key"))
      .filter(Boolean);
    mmOrder.value = JSON.stringify(keys);
  };

  if (mmList && mmOrder) {
    let dragging = null;
    const items = () => Array.from(mmList.querySelectorAll(".mm-item"));

    items().forEach((it) => {
      it.addEventListener("dragstart", (e) => {
        dragging = it;
        it.classList.add("dragging");
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = "move";
          e.dataTransfer.setData("text/plain", it.getAttribute("data-module-key") || "");
        }
      });

      it.addEventListener("dragend", () => {
        it.classList.remove("dragging");
        dragging = null;
        updateOrder();
      });
    });

    mmList.addEventListener("dragover", (e) => {
      e.preventDefault();
      if (!dragging) return;

      const els = items().filter((x) => x !== dragging);
      let closest = { offset: Number.NEGATIVE_INFINITY, element: null };
      for (const child of els) {
        const box = child.getBoundingClientRect();
        const offset = e.clientY - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          closest = { offset, element: child };
        }
      }
      const afterEl = closest.element;

      if (afterEl == null) {
        mmList.appendChild(dragging);
      } else {
        mmList.insertBefore(dragging, afterEl);
      }
    });

    updateOrder();
    if (mmForm) {
      mmForm.addEventListener("submit", () => updateOrder());
    }
  }
} catch (e) {
  // ignore
}

// Top-right profile avatar dropdown
try {
  const avatarBtn = document.querySelector(".top-avatar");
  const profileDd = document.querySelector(".top-profile-dropdown");
  const notifDd = document.querySelector(".notif-dropdown");

  if (avatarBtn && profileDd) {
    avatarBtn.addEventListener("click", (e) => {
      e.preventDefault();
      if (notifDd) {
        notifDd.style.display = "none";
      }
      const isOpen = profileDd.style.display !== "none";
      profileDd.style.display = isOpen ? "none" : "block";
    });

    document.addEventListener("click", (e) => {
      if (!profileDd) return;
      if (profileDd.style.display === "none") return;
      const target = e.target;
      if (!target) return;
      if (avatarBtn.contains(target) || profileDd.contains(target)) return;
      profileDd.style.display = "none";
    });
  }
} catch (e) {
  // ignore
}

// Notifications bell dropdown
function formatRelativeDate(isoLike) {
  if (!isoLike) return "";
  const d = new Date(isoLike);
  if (Number.isNaN(d.getTime())) return String(isoLike);

  const diffMs = Date.now() - d.getTime();
  const sec = Math.floor(diffMs / 1000);
  if (sec < 60) return `${sec}s ago`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min}m ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h ago`;
  const day = Math.floor(hr / 24);
  return `${day}d ago`;
}

async function fetchNotifications() {
  const res = await fetch("notifications_api.php", { credentials: "same-origin" });
  if (!res.ok) return null;
  return await res.json();
}

async function markAllRead(csrf) {
  const res = await fetch("notifications_mark_all_read.php", {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `csrf_token=${encodeURIComponent(csrf)}`,
  });
  if (!res.ok) return false;
  const json = await res.json();
  return Boolean(json && json.ok);
}

function renderNotifDropdown(container, data) {
  if (!container) return;
  const items = Array.isArray(data && data.items ? data.items : []) ? data.items : [];
  const unread = Number(data && data.unread ? data.unread : 0);

  const header = document.createElement("div");
  header.className = "notif-card-header";
  header.innerHTML = `
    <div class="h">Notifications</div>
    <div class="c">${unread}</div>
  `;

  const actions = document.createElement("div");
  actions.className = "notif-card-actions";
  actions.innerHTML = `
    <div class="left">
      <button type="button" class="notif-mark">Mark all read</button>
      <a href="settings.php">Settings</a>
    </div>
  `;

  const list = document.createElement("div");
  list.className = "notif-list";

  if (items.length === 0) {
    const empty = document.createElement("div");
    empty.className = "notif-empty";
    empty.innerHTML = `
      <i class='bx bx-bell-off'></i>
      <div class="t">No new notifications</div>
      <div class="m">You're all caught up.</div>
    `;
    list.appendChild(empty);
  } else {
    items.forEach((n) => {
      const a = document.createElement("a");
      const isUnread = String(n && n.is_read ? n.is_read : 0) === "0";
      a.className = `notif-item${isUnread ? " unread" : ""}`;
      a.href = n && n.link_url ? String(n.link_url) : "#";
      a.innerHTML = `
        <div class="t">${String(n && n.title ? n.title : "")}</div>
        <div class="m">${String(n && n.message ? n.message : "")}</div>
        <div class="d">${formatRelativeDate(n && n.created_at ? n.created_at : "")}</div>
      `;
      list.appendChild(a);
    });
  }

  container.innerHTML = "";
  container.appendChild(header);
  container.appendChild(actions);
  container.appendChild(list);
}

try {
  const bell = document.querySelector(".notif-bell");
  const dropdown = document.querySelector(".notif-dropdown");
  const badge = document.querySelector(".notif-badge");
  if (bell && dropdown) {
    const csrf = bell.getAttribute("data-csrf") || "";

    const refresh = async () => {
      const data = await fetchNotifications();
      if (!data || !data.ok) return;
      const unread = Number(data.unread || 0);
      if (badge) {
        badge.textContent = String(unread);
        badge.style.display = unread > 0 ? "grid" : "none";
      }
      renderNotifDropdown(dropdown, data);

      const markBtn = dropdown.querySelector(".notif-mark");
      if (markBtn) {
        markBtn.addEventListener("click", async () => {
          if (!csrf) return;
          const ok = await markAllRead(csrf);
          if (ok) {
            await refresh();
          }
        });
      }
    };

    refresh();
    setInterval(refresh, 45000);

    bell.addEventListener("click", async (e) => {
      e.preventDefault();
      const isOpen = dropdown.style.display !== "none";
      dropdown.style.display = isOpen ? "none" : "block";
      if (!isOpen) {
        await refresh();
      }
    });

    document.addEventListener("click", (e) => {
      if (!dropdown) return;
      if (dropdown.style.display === "none") return;
      const target = e.target;
      if (!target) return;
      if (bell.contains(target) || dropdown.contains(target)) return;
      dropdown.style.display = "none";
    });
  }
} catch (e) {
  // ignore
}

// Guest flow + details modal (warehouse selection + product items list)
try {
  const modal = document.querySelector("#ims-detail-modal");
  if (!modal) {
    throw new Error("no modal");
  }

  const titleEl = document.querySelector("#ims-modal-title");
  const subEl = document.querySelector("#ims-modal-subtitle");
  const m1l = document.querySelector("#ims-modal-meta1-label");
  const m1v = document.querySelector("#ims-modal-meta1-value");
  const m2l = document.querySelector("#ims-modal-meta2-label");
  const m2v = document.querySelector("#ims-modal-meta2-value");
  const imgEl = document.querySelector("#ims-modal-image");
  const imgEmpty = document.querySelector("#ims-modal-image-empty");
  const actionsEl = document.querySelector("#ims-modal-actions");

  const STORAGE = {
    warehouse: "ims_guest_warehouse",
    items: "ims_guest_items",
  };

  const safeJsonParse = (s, fallback) => {
    try {
      if (!s) return fallback;
      return JSON.parse(s);
    } catch (e) {
      return fallback;
    }
  };

  const getWarehouse = () => safeJsonParse(localStorage.getItem(STORAGE.warehouse), null);
  const setWarehouse = (w) => {
    try {
      localStorage.setItem(STORAGE.warehouse, JSON.stringify(w));
    } catch (e) {}
  };

  const getItems = () => {
    const arr = safeJsonParse(localStorage.getItem(STORAGE.items), []);
    return Array.isArray(arr) ? arr : [];
  };

  const setItems = (arr) => {
    try {
      localStorage.setItem(STORAGE.items, JSON.stringify(arr));
    } catch (e) {}
  };

  const renderGuestPanel = () => {
    const pill = document.querySelector("#guest-warehouse-pill");
    const itemsHost = document.querySelector("#guest-items");
    const cont = document.querySelector("#guest-continue");
    if (!pill || !itemsHost) return;

    const w = getWarehouse();
    if (w && w.name) {

      const img = w.imageSrc ? String(w.imageSrc) : "";
      const thumb = img
        ? `<img class="guest-warehouse-thumb" src="${img}" alt="">`
        : `<div class="guest-warehouse-thumb guest-warehouse-thumb--empty">No image</div>`;
      pill.innerHTML = `
        <div class="guest-warehouse">
          ${thumb}
          <div class="guest-warehouse-meta">
            <div class="guest-warehouse-title">${String(w.name)}</div>
            <div class="guest-warehouse-actions">
              <a class="btn" href="locations.php" style="padding:6px 10px;">Change</a>
              <button type="button" class="btn danger" id="guest-clear-warehouse" style="padding:6px 10px;">Clear</button>
            </div>
          </div>
        </div>
      `;
      const clearBtn = document.querySelector("#guest-clear-warehouse");
      if (clearBtn) {
        clearBtn.addEventListener("click", () => {
          try {
            localStorage.removeItem(STORAGE.warehouse);
          } catch (e) {}
          renderGuestPanel();
        });
      }
    } else {
      pill.innerHTML = `<div class="muted">No warehouse selected. Go to <a href="locations.php">Warehouses</a> and select one.</div>`;
    }

    if (cont) {
      const ok = Boolean(w && w.name);
      cont.classList.toggle("disabled", !ok);
      cont.setAttribute("aria-disabled", ok ? "false" : "true");
      cont.style.pointerEvents = ok ? "auto" : "none";
      cont.style.opacity = ok ? "1" : "0.55";
    }

    const items = getItems();
    if (items.length === 0) {
      itemsHost.innerHTML = '<div class="muted">No items selected yet. Open a product and add a quantity.</div>';
      return;
    }

    const wrap = document.createElement("div");
    wrap.className = "guest-items";

    items.forEach((it, idx) => {

      const row = document.createElement("div");
      row.className = "guest-item";
      row.innerHTML = `
        <div>
          <div class="t">${String(it.name || "")}</div>
          <div class="m">SKU: ${String(it.sku || "")} · Qty: ${Number(it.qty || 0)}</div>
        </div>
        <button type="button" class="btn danger" style="padding:6px 10px;" data-idx="${idx}">Remove</button>
      `;

      const btn = row.querySelector("button");
      if (btn) {
        btn.addEventListener("click", () => {
          const next = getItems().filter((_, i) => i !== idx);
          setItems(next);
          renderGuestPanel();
        });
      }

      wrap.appendChild(row);
    });

    itemsHost.innerHTML = "";
    itemsHost.appendChild(wrap);
  };

  const wireLocationsQuickAdd = () => {
    const skuEl = document.querySelector("#guest-add-sku");
    const nameEl = document.querySelector("#guest-add-name");
    const catEl = document.querySelector("#guest-add-category");
    const unitCostEl = document.querySelector("#guest-add-unit-cost");
    const unitPriceEl = document.querySelector("#guest-add-unit-price");
    const reorderEl = document.querySelector("#guest-add-reorder-level");
    const qtyEl = document.querySelector("#guest-add-qty");
    const addBtn = document.querySelector("#guest-add-btn");
    const clearBtn = document.querySelector("#guest-clear-items");
    if (!skuEl || !qtyEl || !addBtn) return;

    const addItem = () => {
      const sku = String(skuEl.value || "").trim();
      const name = nameEl ? String(nameEl.value || "").trim() : "";
      const categoryId = catEl ? Number(catEl.value || 0) || 0 : 0;
      const unitCost = unitCostEl ? Number(unitCostEl.value || 0) || 0 : 0;
      const unitPrice = unitPriceEl ? Number(unitPriceEl.value || 0) || 0 : 0;
      const reorderLevel = reorderEl ? Number(reorderEl.value || 0) || 0 : 0;
      const qty = Math.max(1, Number(qtyEl.value || 1));
      if (!sku && !name) return;
      const cur = getItems();
      cur.push({
        sku: sku,
        name: name || sku,
        qty,
        category_id: categoryId,
        unit_cost: unitCost,
        unit_price: unitPrice,
        reorder_level: reorderLevel,
      });
      setItems(cur);
      skuEl.value = "";
      if (nameEl) nameEl.value = "";
      if (catEl) catEl.value = "";
      if (unitCostEl) unitCostEl.value = "";
      if (unitPriceEl) unitPriceEl.value = "";
      if (reorderEl) reorderEl.value = "";
      qtyEl.value = "1";
      renderGuestPanel();
    };

    addBtn.addEventListener("click", addItem);
    skuEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addItem();
      }
    });
    qtyEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addItem();
      }
    });

    if (nameEl) {
      nameEl.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          addItem();
        }
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        setItems([]);
        renderGuestPanel();
      });
    }
  };

  const closeModal = () => {
    modal.style.display = "none";
    if (imgEl) imgEl.src = "";
  };

  const openModal = (payload) => {

    if (titleEl) titleEl.textContent = payload.title || "";
    if (subEl) subEl.textContent = payload.subtitle || "";
    if (m1l) m1l.textContent = payload.meta1Label || "";
    if (m1v) m1v.textContent = payload.meta1Value || "";
    if (m2l) m2l.textContent = payload.meta2Label || "";
    if (m2v) m2v.textContent = payload.meta2Value || "";

    const src = payload.imageSrc || "";
    if (imgEl && imgEmpty) {
      if (src) {
        imgEl.src = src;
        imgEl.style.display = "block";
        imgEmpty.style.display = "none";
      } else {
        imgEl.src = "";
        imgEl.style.display = "none";
        imgEmpty.style.display = "block";
      }
    }

    if (actionsEl) {
      actionsEl.innerHTML = "";
      const type = payload.modalType || "";

      if (type === "location") {

        const selectBtn = document.createElement("button");
        selectBtn.type = "button";
        selectBtn.className = "btn primary";
        selectBtn.textContent = "Select Warehouse";
        selectBtn.addEventListener("click", () => {
          setWarehouse({ id: payload.locationId || 0, name: payload.title || "", imageSrc: payload.imageSrc || "" });
          renderGuestPanel();
          closeModal();
        });

        actionsEl.appendChild(selectBtn);

        const contBtn = document.createElement("a");
        contBtn.className = "btn";
        contBtn.href = "product.php";
        contBtn.textContent = "Continue";
        contBtn.addEventListener("click", () => {
          setWarehouse({ id: payload.locationId || 0, name: payload.title || "", imageSrc: payload.imageSrc || "" });
        });
        actionsEl.appendChild(contBtn);
      }

      if (type === "product") {
        const w = getWarehouse();
        if (!w || !w.name) {
          const hint = document.createElement("div");
          hint.className = "muted";
          hint.innerHTML = 'Select a warehouse first in <a href="locations.php">Warehouses</a>.';
          actionsEl.appendChild(hint);
        } else {
          const qty = document.createElement("input");
          qty.type = "number";
          qty.min = "1";
          qty.value = "1";
          qty.className = "input";
          qty.style.width = "140px";

          const add = document.createElement("button");
          add.type = "button";
          add.className = "btn primary";
          add.textContent = "Add Item";
          add.addEventListener("click", () => {
            const q = Math.max(1, Number(qty.value || 1));
            const cur = getItems();
            cur.push({
              sku: payload.sku || "",
              name: payload.title || "",
              qty: q,
              category_id: Number(payload.categoryId || 0) || 0,
              unit_cost: Number(payload.unitCost || 0) || 0,
              unit_price: Number(payload.unitPrice || 0) || 0,
              reorder_level: Number(payload.reorderLevel || 0) || 0,
            });
            setItems(cur);
            renderGuestPanel();
            closeModal();
          });

          actionsEl.appendChild(qty);
          actionsEl.appendChild(add);
        }
      }
    }

    modal.style.display = "block";
  };

  modal.addEventListener("click", (e) => {
    const t = e.target;
    if (!t) return;
    if (t.getAttribute && t.getAttribute("data-modal-close") === "1") {
      closeModal();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (modal.style.display === "none") return;
    if (e.key === "Escape") closeModal();
  });

  const bindOpeners = () => {
    const openers = document.querySelectorAll(".js-open-modal");
    openers.forEach((el) => {
      if (el.__imsBound) return;
      el.__imsBound = true;

      const handler = () => {
        const d = el.dataset || {};
        openModal({
          title: d.title || "",
          subtitle: d.subtitle || "",
          meta1Label: d.meta1Label || "",
          meta1Value: d.meta1Value || "",
          meta2Label: d.meta2Label || "",
          meta2Value: d.meta2Value || "",
          imageSrc: d.imageSrc || "",
          modalType: d.modalType || "",
          sku: d.sku || "",
          categoryId: Number(d.categoryId || 0) || 0,
          unitCost: Number(d.unitCost || 0) || 0,
          unitPrice: Number(d.unitPrice || 0) || 0,
          reorderLevel: Number(d.reorderLevel || 0) || 0,
          locationId: Number(d.locationId || 0) || 0,
        });
      };

      el.addEventListener("click", (e) => {
        if (e.target && e.target.closest && e.target.closest("a,button,form")) return;
        handler();
      });

      el.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          handler();
        }
      });
    });
  };

  const wireGuestSubmitApproval = () => {
    const btn = document.querySelector("#guest-submit-approval");
    const noteEl = document.querySelector("#guest-approval-note");
    const statusEl = document.querySelector("#guest-submit-status");
    if (!btn) return;

    if (btn.__imsBound) return;
    btn.__imsBound = true;

    let inFlight = false;

    const setStatus = (msg, isError) => {
      if (!statusEl) return;
      statusEl.style.display = "block";
      statusEl.textContent = msg;
      statusEl.style.color = isError ? "#ef4444" : "";
    };

    btn.addEventListener("click", async () => {
      if (inFlight) return;
      const w = getWarehouse();
      const items = getItems();
      if (!w || !w.name) {
        setStatus("Select a warehouse first.", true);
        return;
      }
      if (!Array.isArray(items) || items.length === 0) {
        setStatus("Add at least 1 item first.", true);
        return;
      }

      const csrf = btn.getAttribute("data-csrf") || "";
      const payload = {
        warehouse: w,
        items: items,
        note: noteEl ? String(noteEl.value || "").trim() : "",
      };

      btn.disabled = true;
      inFlight = true;
      try {
        const res = await fetch("guest_request_submit.php", {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify(Object.assign(payload, { csrf_token: csrf })),
        });

        const json = await res.json().catch(() => null);
        if (!res.ok || !json || !json.ok) {
          setStatus("Failed to submit request.", true);
          return;
        }

        setStatus("Submitted. Waiting for approval.", false);
        setItems([]);
        renderGuestPanel();
        if (noteEl) noteEl.value = "";

        setTimeout(() => {
          try {
            window.location.href = "product.php#guest-requests";
          } catch (e) {
            // ignore
          }
        }, 250);
      } catch (e) {
        setStatus("Failed to submit request.", true);
      } finally {
        btn.disabled = false;
        inFlight = false;
      }
    });
  };

  const filterGuestProductList = () => {
    if (!document.querySelector("#guest-submit-approval")) return;
    const tbl = document.querySelector(".table");
    if (!tbl) return;

    const rows = Array.from(tbl.querySelectorAll("tbody tr"));
    if (rows.length === 0) return;

    const items = getItems();
    const skuSet = new Set(
      Array.isArray(items)
        ? items
            .map((x) => (x && x.sku ? String(x.sku).trim() : ""))
            .filter((x) => x)
        : []
    );

    rows.forEach((tr) => {
      const sku = tr.getAttribute("data-sku") || "";
      if (!skuSet.size) {
        tr.style.display = "none";
        return;
      }
      tr.style.display = skuSet.has(String(sku)) ? "" : "none";
    });
  };

  bindOpeners();
  renderGuestPanel();
  wireLocationsQuickAdd();
  wireGuestSubmitApproval();
  filterGuestProductList();
} catch (e) {
  // ignore
}