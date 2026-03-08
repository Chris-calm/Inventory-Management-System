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