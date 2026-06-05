import { useState, useCallback } from "react";

// ------------------------------------------------------------------
// Mobile Navigation Component
// Thème : dark organique · Polices : Playfair Display + Geist Mono
// Patterns : Tab Bar · Drawer latéral · Bottom Sheet
// ------------------------------------------------------------------

const TABS = [
  { id: "home",    icon: "ti-home",     label: "Accueil" },
  { id: "explore", icon: "ti-compass",  label: "Explorer" },
  { id: "notifs",  icon: "ti-bell",     label: "Notifs",  badge: 3 },
  { id: "profile", icon: "ti-user",     label: "Profil" },
];

const DRAWER_ITEMS = [
  { icon: "ti-home",     label: "Accueil",       tab: "home" },
  { icon: "ti-compass",  label: "Explorer",      tab: "explore" },
  { icon: "ti-bell",     label: "Notifications", tab: "notifs" },
  null, // divider
  { icon: "ti-settings", label: "Paramètres" },
  { icon: "ti-help",     label: "Aide" },
  { icon: "ti-logout",   label: "Déconnexion",   danger: true },
];

const SHEET_OPTIONS = [
  { icon: "ti-edit",     label: "Modifier",         color: "#8fbc8f" },
  { icon: "ti-copy",     label: "Dupliquer",         color: "#7f77dd" },
  { icon: "ti-download", label: "Exporter en PDF",   color: "#1d9e75" },
  { icon: "ti-share",    label: "Partager",          color: "#ba7517" },
  { icon: "ti-trash",    label: "Supprimer",         color: "#e05c5c", danger: true },
];

// ---------- Sub-components ----------

function StatusBar() {
  return (
    <div style={{ position: "absolute", top: 0, left: 0, right: 0, height: 44, display: "flex", alignItems: "flex-start", justifyContent: "space-between", padding: "10px 20px 0", zIndex: 40 }}>
      <span style={{ fontSize: 12, fontWeight: 500, color: "#e8e4dc", fontFamily: "'Geist Mono', monospace" }}>9:41</span>
      <div style={{ display: "flex", gap: 5, alignItems: "center" }}>
        <svg width="15" height="11" viewBox="0 0 15 11" fill="none" aria-hidden="true"><rect x="0" y="3" width="3" height="8" rx="1" fill="#e8e4dc"/><rect x="4" y="2" width="3" height="9" rx="1" fill="#e8e4dc"/><rect x="8" y="0" width="3" height="11" rx="1" fill="#e8e4dc"/><rect x="12" y="0" width="3" height="11" rx="1" fill="#e8e4dc" opacity=".3"/></svg>
        <svg width="25" height="12" viewBox="0 0 25 12" fill="none" aria-hidden="true"><rect x=".5" y=".5" width="21" height="11" rx="3.5" stroke="#e8e4dc" strokeOpacity=".35"/><rect x="2" y="2" width="16" height="8" rx="2" fill="#e8e4dc"/><path d="M23 4v4a2 2 0 000-4z" fill="#e8e4dc" opacity=".4"/></svg>
      </div>
    </div>
  );
}

function TabBar({ activeTab, onSwitch }) {
  return (
    <div style={{ position: "absolute", bottom: 0, left: 0, right: 0, height: 74, background: "#141416", borderTop: "0.5px solid #252528", display: "flex", alignItems: "center", justifyContent: "space-around", padding: "0 8px 14px", zIndex: 30 }}>
      {TABS.map((tab) => {
        const active = activeTab === tab.id;
        return (
          <button
            key={tab.id}
            onClick={() => onSwitch(tab.id)}
            aria-label={tab.label}
            style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 4, padding: "6px 12px", borderRadius: 12, cursor: "pointer", background: "none", border: "none", position: "relative" }}
          >
            <div style={{ position: "relative" }}>
              <i className={`ti ${tab.icon}`} aria-hidden="true" style={{ fontSize: 20, color: active ? "#8fbc8f" : "#6b6b72", transition: "color .18s" }} />
              {tab.badge && !active && (
                <div style={{ position: "absolute", top: -2, right: -4, width: 7, height: 7, borderRadius: "50%", background: "#e05c5c", border: "1.5px solid #141416" }} />
              )}
            </div>
            <span style={{ fontSize: 9, color: active ? "#8fbc8f" : "#6b6b72", letterSpacing: ".08em", fontFamily: "'Geist Mono', monospace", transition: "color .18s" }}>{tab.label}</span>
            <div style={{ width: 4, height: 4, borderRadius: "50%", background: "#8fbc8f", opacity: active ? 1 : 0, transition: "opacity .18s" }} />
          </button>
        );
      })}
    </div>
  );
}

function Drawer({ open, onClose, onNavigate, activeTab }) {
  return (
    <>
      <div
        onClick={onClose}
        style={{ position: "absolute", inset: 0, background: "rgba(0,0,0,.55)", zIndex: 60, opacity: open ? 1 : 0, pointerEvents: open ? "all" : "none", transition: "opacity .3s" }}
      />
      <div style={{ position: "absolute", top: 0, left: 0, bottom: 0, width: 240, background: "#141416", zIndex: 70, transform: open ? "translateX(0)" : "translateX(-100%)", transition: "transform .32s cubic-bezier(.4,0,.2,1)", borderRight: "0.5px solid #252528" }}>
        <div style={{ padding: "54px 20px 20px", borderBottom: "0.5px solid #252528" }}>
          <div style={{ width: 46, height: 46, borderRadius: 14, background: "#1e2e1e", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: "'Playfair Display', serif", fontSize: 20, color: "#8fbc8f", marginBottom: 10 }}>S</div>
          <div style={{ fontSize: 14, color: "#e8e4dc", fontWeight: 500 }}>Sophie Martin</div>
          <div style={{ fontSize: 11, color: "#6b6b72", marginTop: 2, letterSpacing: ".04em", fontFamily: "'Geist Mono', monospace" }}>Designer produit · Pro</div>
        </div>
        <div style={{ padding: "16px 0" }}>
          {DRAWER_ITEMS.map((item, i) =>
            item === null ? (
              <div key={i} style={{ height: "0.5px", background: "#252528", margin: "8px 20px" }} />
            ) : (
              <div
                key={i}
                onClick={() => { if (item.tab) { onNavigate(item.tab); onClose(); } }}
                style={{ display: "flex", alignItems: "center", gap: 12, padding: "11px 20px", cursor: "pointer", background: item.tab === activeTab ? "#1e2e1e" : "transparent" }}
              >
                <i className={`ti ${item.icon}`} aria-hidden="true" style={{ fontSize: 17, color: item.danger ? "#e05c5c" : item.tab === activeTab ? "#8fbc8f" : "#6b6b72" }} />
                <span style={{ fontSize: 12, color: item.danger ? "#e05c5c" : item.tab === activeTab ? "#e8e4dc" : "#aaa", letterSpacing: ".05em", fontFamily: "'Geist Mono', monospace" }}>{item.label}</span>
              </div>
            )
          )}
        </div>
      </div>
    </>
  );
}

function BottomSheet({ open, onClose }) {
  return (
    <>
      <div onClick={onClose} style={{ position: "absolute", inset: 0, background: "rgba(0,0,0,.5)", zIndex: 80, opacity: open ? 1 : 0, pointerEvents: open ? "all" : "none", transition: "opacity .28s" }} />
      <div style={{ position: "absolute", bottom: 0, left: 0, right: 0, background: "#17171a", borderRadius: "20px 20px 0 0", borderTop: "0.5px solid #2a2a2e", zIndex: 90, transform: open ? "translateY(0)" : "translateY(100%)", transition: "transform .32s cubic-bezier(.4,0,.2,1)" }}>
        <div style={{ width: 36, height: 4, background: "#333", borderRadius: 2, margin: "12px auto 0" }} />
        <div style={{ padding: "20px 20px 34px" }}>
          <div style={{ fontFamily: "'Playfair Display', serif", fontSize: 16, color: "#e8e4dc", marginBottom: 14 }}>Options du document</div>
          {SHEET_OPTIONS.map((opt, i) => (
            <div key={i} onClick={onClose} style={{ display: "flex", alignItems: "center", gap: 12, padding: "12px 0", borderBottom: i < SHEET_OPTIONS.length - 1 ? "0.5px solid #252528" : "none", cursor: "pointer" }}>
              <i className={`ti ${opt.icon}`} aria-hidden="true" style={{ fontSize: 18, width: 32, textAlign: "center", color: opt.color }} />
              <span style={{ fontSize: 13, color: opt.danger ? opt.color : "#ccc", fontFamily: "'Geist Mono', monospace" }}>{opt.label}</span>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}

// ---------- Page screens ----------

function HomePage({ onOpenDrawer, onOpenSheet, onSwitchTab }) {
  return (
    <div style={{ padding: "54px 20px 76px", height: "100%", overflowY: "auto" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 2 }}>
        <div>
          <div style={{ fontFamily: "'Playfair Display', serif", fontSize: 22, color: "#e8e4dc" }}>Accueil</div>
          <div style={{ fontSize: 11, color: "#6b6b72", letterSpacing: ".08em", fontFamily: "'Geist Mono', monospace" }}>Mardi 26 mai</div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <IconBtn onClick={onOpenDrawer} icon="ti-menu-2" />
          <IconBtn onClick={() => onSwitchTab("notifs")} icon="ti-bell" badge />
        </div>
      </div>
      <SearchBar />
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: 12 }}>
        {[["4 821","Clients actifs"],["+ 28%","Croissance"],["156k","Revenu déc."],["2.3%","Churn rate"]].map(([v, l]) => (
          <div key={l} style={{ background: "#17171a", border: "0.5px solid #2a2a2e", borderRadius: 12, padding: "12px 14px" }}>
            <div style={{ fontSize: 18, fontWeight: 500, color: "#e8e4dc", fontFamily: "'Geist Mono', monospace" }}>{v}</div>
            <div style={{ fontSize: 10, color: "#6b6b72", marginTop: 2, letterSpacing: ".08em", fontFamily: "'Geist Mono', monospace" }}>{l}</div>
          </div>
        ))}
      </div>
      <div style={{ marginTop: 16, fontSize: 10, color: "#6b6b72", letterSpacing: ".1em", fontFamily: "'Geist Mono', monospace" }}>RÉCENTS</div>
      <CardItem color="#1e2e1e" textColor="#8fbc8f" initial="A" title="Analyse Q4" sub="Il y a 12 min" badge="Actif" badgeBg="#1e2e1e" badgeColor="#8fbc8f" />
      <CardItem color="#1e1e2e" textColor="#7f77dd" initial="R" title="Rapport mensuel" sub="Hier · Tap pour options" badge="Draft" badgeBg="#252530" badgeColor="#7f77dd" onClick={onOpenSheet} />
      <div onClick={onOpenSheet} style={{ background: "#17171a", border: "0.5px solid #2a2a2e", borderRadius: 14, padding: "13px 16px", display: "flex", alignItems: "center", justifyContent: "space-between", cursor: "pointer", marginTop: 12 }}>
        <span style={{ fontSize: 12, color: "#aaa", fontFamily: "'Geist Mono', monospace" }}>Filtres & options</span>
        <i className="ti ti-chevron-up" aria-hidden="true" style={{ fontSize: 16, color: "#6b6b72" }} />
      </div>
    </div>
  );
}

function ExplorePage() {
  return (
    <div style={{ padding: "54px 20px 76px" }}>
      <div style={{ fontFamily: "'Playfair Display', serif", fontSize: 22, color: "#e8e4dc" }}>Explorer</div>
      <div style={{ fontSize: 11, color: "#6b6b72", letterSpacing: ".08em", fontFamily: "'Geist Mono', monospace" }}>Découvrir</div>
      <SearchBar placeholder="Rechercher un projet…" />
      <div style={{ marginTop: 14, fontSize: 10, color: "#6b6b72", letterSpacing: ".1em", fontFamily: "'Geist Mono', monospace" }}>PROJETS</div>
      <CardItem color="#2e1e1e" textColor="#d85a30" initial="D" title="Design system v3" sub="12 contributeurs · 89 composants" />
      <CardItem color="#1e2a2e" textColor="#1d9e75" initial="M" title="Mobile nav kit" sub="4 contributeurs · 23 écrans" />
      <CardItem color="#2e2a1e" textColor="#ba7517" initial="I" title="Impeccable skills" sub="1 contributeur · 7 domaines" />
    </div>
  );
}

function NotifsPage() {
  return (
    <div style={{ padding: "54px 20px 76px" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div>
          <div style={{ fontFamily: "'Playfair Display', serif", fontSize: 22, color: "#e8e4dc" }}>Notifications</div>
          <div style={{ fontSize: 11, color: "#6b6b72", letterSpacing: ".08em", fontFamily: "'Geist Mono', monospace" }}>3 non lues</div>
        </div>
        <IconBtn icon="ti-check" />
      </div>
      <div style={{ marginTop: 12 }}>
        <NotifItem accent="#8fbc8f" icon="ti-chart-bar" title="Rapport Q4 prêt" sub="Il y a 5 min · Analyses complètes" />
        <NotifItem accent="#7f77dd" icon="ti-user" title="Nouveau membre" sub="Il y a 1h · Sophie rejoint l'équipe" style={{ marginTop: 8 }} />
        <div style={{ marginTop: 8, opacity: .5 }}>
          <NotifItem icon="ti-bell" title="Backup terminé" sub="Hier · Données sauvegardées" />
        </div>
      </div>
    </div>
  );
}

function ProfilePage() {
  return (
    <div style={{ padding: "54px 20px 76px" }}>
      <div style={{ textAlign: "center", paddingTop: 16 }}>
        <div style={{ width: 64, height: 64, borderRadius: 20, background: "#1e2e1e", display: "flex", alignItems: "center", justifyContent: "center", margin: "0 auto", fontFamily: "'Playfair Display', serif", fontSize: 28, color: "#8fbc8f" }}>S</div>
        <div style={{ fontSize: 16, color: "#e8e4dc", marginTop: 10, fontWeight: 500 }}>Sophie Martin</div>
        <div style={{ fontSize: 11, color: "#6b6b72", marginTop: 2, letterSpacing: ".06em", fontFamily: "'Geist Mono', monospace" }}>Designer produit</div>
      </div>
      <div style={{ marginTop: 20, fontSize: 10, color: "#6b6b72", letterSpacing: ".1em", fontFamily: "'Geist Mono', monospace" }}>PARAMÈTRES</div>
      {[["ti-user", "Profil"], ["ti-lock", "Sécurité"], ["ti-bell", "Notifications"]].map(([icon, label]) => (
        <div key={label} style={{ background: "#17171a", border: "0.5px solid #2a2a2e", borderRadius: 14, padding: "13px 16px", display: "flex", alignItems: "center", gap: 12, marginTop: 6, cursor: "pointer" }}>
          <i className={`ti ${icon}`} aria-hidden="true" style={{ fontSize: 17, color: "#6b6b72" }} />
          <span style={{ fontSize: 13, color: "#ccc", flex: 1, fontFamily: "'Geist Mono', monospace" }}>{label}</span>
          <i className="ti ti-chevron-right" aria-hidden="true" style={{ fontSize: 15, color: "#6b6b72" }} />
        </div>
      ))}
    </div>
  );
}

// ---------- Shared micro-components ----------

function IconBtn({ onClick, icon, badge }) {
  return (
    <div onClick={onClick} style={{ width: 34, height: 34, borderRadius: 10, background: "#17171a", border: "0.5px solid #252528", display: "flex", alignItems: "center", justifyContent: "center", cursor: "pointer", color: "#aaa", fontSize: 17, position: "relative" }}>
      <i className={`ti ${icon}`} aria-hidden="true" />
      {badge && <div style={{ position: "absolute", top: 4, right: 4, width: 7, height: 7, borderRadius: "50%", background: "#e05c5c", border: "1.5px solid #17171a" }} />}
    </div>
  );
}

function SearchBar({ placeholder = "Rechercher…" }) {
  return (
    <div style={{ background: "#17171a", border: "0.5px solid #252528", borderRadius: 12, padding: "9px 14px", display: "flex", alignItems: "center", gap: 8, marginTop: 10 }}>
      <i className="ti ti-search" aria-hidden="true" style={{ fontSize: 15, color: "#6b6b72" }} />
      <span style={{ fontSize: 12, color: "#6b6b72", letterSpacing: ".04em", fontFamily: "'Geist Mono', monospace" }}>{placeholder}</span>
    </div>
  );
}

function CardItem({ color, textColor, initial, title, sub, badge, badgeBg, badgeColor, onClick }) {
  return (
    <div onClick={onClick} style={{ background: "#17171a", border: "0.5px solid #2a2a2e", borderRadius: 14, padding: "14px 16px", marginTop: 12, cursor: onClick ? "pointer" : "default" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
        <div style={{ width: 38, height: 38, borderRadius: 10, background: color, color: textColor, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 15, flexShrink: 0, fontFamily: "'Playfair Display', serif" }}>{initial}</div>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 13, color: "#e8e4dc", fontWeight: 500, fontFamily: "'Geist Mono', monospace" }}>{title}</div>
          <div style={{ fontSize: 11, color: "#6b6b72", marginTop: 2, letterSpacing: ".04em", fontFamily: "'Geist Mono', monospace" }}>{sub}</div>
        </div>
        {badge && <div style={{ fontSize: 10, padding: "2px 8px", borderRadius: 20, background: badgeBg, color: badgeColor, fontFamily: "'Geist Mono', monospace", flexShrink: 0 }}>{badge}</div>}
      </div>
    </div>
  );
}

function NotifItem({ accent, icon, title, sub }) {
  return (
    <div style={{ background: "#17171a", borderLeft: accent ? `2px solid ${accent}` : "none", borderRight: "0.5px solid #2a2a2e", borderTop: "0.5px solid #2a2a2e", borderBottom: "0.5px solid #2a2a2e", borderRadius: accent ? "0 14px 14px 0" : 14, padding: "14px 16px" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
        <div style={{ width: 38, height: 38, borderRadius: 10, background: accent ? `${accent}22` : "#1a1a1d", color: accent || "#6b6b72", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
          <i className={`ti ${icon}`} aria-hidden="true" style={{ fontSize: 16 }} />
        </div>
        <div>
          <div style={{ fontSize: 13, color: "#e8e4dc", fontWeight: 500, fontFamily: "'Geist Mono', monospace" }}>{title}</div>
          <div style={{ fontSize: 11, color: "#6b6b72", marginTop: 2, letterSpacing: ".04em", fontFamily: "'Geist Mono', monospace" }}>{sub}</div>
        </div>
      </div>
    </div>
  );
}

// ---------- Root ----------

export default function MobileNav() {
  const [activeTab, setActiveTab] = useState("home");
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);

  const switchTab = useCallback((id) => setActiveTab(id), []);

  const pageProps = {
    onOpenDrawer: () => setDrawerOpen(true),
    onOpenSheet:  () => setSheetOpen(true),
    onSwitchTab:  switchTab,
  };

  return (
    <>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600&family=Geist+Mono:wght@300;400;500&display=swap');
      `}</style>
      <div style={{ display: "flex", justifyContent: "center", alignItems: "center", minHeight: "100vh", background: "#0a0a0b" }}>
        <div style={{ width: 320, height: 640, background: "#0d0d0f", borderRadius: 44, border: "1.5px solid #2a2a2e", position: "relative", overflow: "hidden" }}>
          <div style={{ position: "absolute", top: 0, left: "50%", transform: "translateX(-50%)", width: 100, height: 30, background: "#0d0d0f", borderRadius: "0 0 18px 18px", zIndex: 50 }} />
          <StatusBar />
          <div style={{ position: "absolute", inset: 0 }}>
            {activeTab === "home"    && <HomePage    {...pageProps} />}
            {activeTab === "explore" && <ExplorePage {...pageProps} />}
            {activeTab === "notifs"  && <NotifsPage  {...pageProps} />}
            {activeTab === "profile" && <ProfilePage {...pageProps} />}
          </div>
          <TabBar activeTab={activeTab} onSwitch={switchTab} />
          <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)} onNavigate={switchTab} activeTab={activeTab} />
          <BottomSheet open={sheetOpen} onClose={() => setSheetOpen(false)} />
        </div>
      </div>
    </>
  );
}
