// --- داتای بازرگانییەکان: کۆمپانیاکانی بەهێزکراو بە NexoraCore ---
const businesses = [
  { name: "Brew & Co", type: "قاوەخانە", emoji: "☕", banner: "linear-gradient(135deg,#f59e0b,#b45309)",
    desc: "زنجیرەیەکی قاوەی تایبەتمەند بە ۴ فرۆشگا کە پەرداخ و دڵسۆزی لەسەر NexoraCore بەڕێوەدەبات.",
    stores: "۴ فرۆشگا", metric: "۱۸هەزار فرۆشتن/مانگ" },
  { name: "UrbanMart", type: "فرۆشگا", emoji: "🛍️", banner: "linear-gradient(135deg,#4f46e5,#7c3aed)",
    desc: "فرۆشگای جلوبەرگ کە کۆگای کاتی ڕاست لە هەموو شوێنێکدا هاوکات دەکات.",
    stores: "۹ فرۆشگا", metric: "۴۲هەزار بەرهەم" },
  { name: "Fork & Fire", type: "چێشتخانە", emoji: "🍽️", banner: "linear-gradient(135deg,#ef4444,#b91c1c)",
    desc: "چێشتخانەی خزمەتگوزاری تەواو بە داواکاری سەر مێز و پارەدانی حیسابی دابەشکراو.",
    stores: "۲ فرۆشگا", metric: "$۱.۲ملیۆن/ساڵ" },
  { name: "Green Grocer", type: "بەقاڵی", emoji: "🥬", banner: "linear-gradient(135deg,#22c55e,#15803d)",
    desc: "بەقاڵی گەڕەک کە کاڵای زوو خراپبوو بە ئاگادارکردنەوەی خۆکار بەدواداچوون دەکات.",
    stores: "۳ فرۆشگا", metric: "۶هەزار کاڵا" },
  { name: "Peak Pharmacy", type: "دەرمانخانە", emoji: "💊", banner: "linear-gradient(135deg,#06b6d4,#0e7490)",
    desc: "زنجیرە دەرمانخانە بە تۆماری پارێزراو و پەرداخی خێرای ڕەچەتە.",
    stores: "۷ فرۆشگا", metric: "٪۹۹.۹۹ کارکردن" },
  { name: "Nova Retail", type: "فرۆشگا", emoji: "🏬", banner: "linear-gradient(135deg,#8b5cf6,#6d28d9)",
    desc: "فرۆشگای ئەلیکترۆنیک کە سکانکردنی بارکۆد و بەدواداچوونی گەرەنتی تێدایە.",
    stores: "۱۲ فرۆشگا", metric: "۳۱۰ ستاف" },
  { name: "Bean Dream", type: "قاوەخانە", emoji: "🥐", banner: "linear-gradient(135deg,#d97706,#92400e)",
    desc: "قاوەخانە-نانەواخانە کە ڕۆژانە هەزاران کەس بە تێرمیناڵی بێ-ئینتەرنێت خزمەت دەکات.",
    stores: "۵ فرۆشگا", metric: "۲۴هەزار فرۆشتن/مانگ" },
  { name: "Harbor Bistro", type: "چێشتخانە", emoji: "🦐", banner: "linear-gradient(135deg,#0ea5e9,#0369a1)",
    desc: "بیسترۆی کەناری دەریا کە جێگرتن، داواکاری و پارەدان لە یەک داتابەیس بەڕێوەدەبات.",
    stores: "۱ فرۆشگا", metric: "$۶۸۰هەزار/ساڵ" },
  { name: "FreshFields", type: "بەقاڵی", emoji: "🍎", banner: "linear-gradient(135deg,#84cc16,#4d7c0f)",
    desc: "بازاڕی ئۆرگانیک بە نرخی دڵسۆزی و کۆگای بەستراو بە دابینکەرەکان.",
    stores: "۶ فرۆشگا", metric: "۱۱هەزار کاڵا" },
];

const grid = document.getElementById("businessGrid");

function renderBusinesses(filter = "all") {
  const list = filter === "all" ? businesses : businesses.filter(b => b.type === filter);
  grid.innerHTML = list.map(b => `
    <article class="biz-card">
      <div class="biz-banner" style="background:${b.banner}">${b.emoji}</div>
      <div class="biz-body">
        <div class="biz-top">
          <h3>${b.name}</h3>
          <span class="biz-tag">${b.type}</span>
        </div>
        <p>${b.desc}</p>
        <div class="biz-meta">
          <div><strong>${b.stores.split(" ")[0]}</strong>${b.stores.split(" ").slice(1).join(" ")}</div>
          <div><strong>${b.metric.split(" ")[0]}</strong>${b.metric.split(" ").slice(1).join(" ")}</div>
        </div>
      </div>
    </article>
  `).join("");
}
renderBusinesses();

// فلتەرەکان
document.getElementById("filterBar").addEventListener("click", (e) => {
  const btn = e.target.closest(".chip");
  if (!btn) return;
  document.querySelectorAll(".chip").forEach(c => c.classList.remove("active"));
  btn.classList.add("active");
  renderBusinesses(btn.dataset.filter);
});

// لیستی مۆبایل
const navToggle = document.getElementById("navToggle");
const navLinks = document.getElementById("navLinks");
navToggle.addEventListener("click", () => navLinks.classList.toggle("open"));
navLinks.addEventListener("click", (e) => {
  if (e.target.tagName === "A") navLinks.classList.remove("open");
});

// فۆرمی دیمۆ (تەنها لای پێشەوە — هیچ داتایەک نانێردرێت)
const form = document.getElementById("demoForm");
const note = document.getElementById("formNote");
form.addEventListener("submit", (e) => {
  e.preventDefault();
  const data = new FormData(form);
  if (!data.get("business") || !data.get("email")) {
    note.style.color = "#f87171";
    note.textContent = "تکایە هەردوو خانەکە پڕبکەرەوە.";
    return;
  }
  note.style.color = "#4ade80";
  note.textContent = `سوپاس! بەم زووانە پەیوەندی بە ${data.get("business")} دەکەین.`;
  form.reset();
  if (window.NEXORA_REGISTER_URL) {
    setTimeout(() => { window.location.href = window.NEXORA_REGISTER_URL; }, 900);
  }
});
