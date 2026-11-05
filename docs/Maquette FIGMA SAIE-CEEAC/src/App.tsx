import { useMemo, useState } from "react"

type Page = "dashboard" | "contents" | "media" | "documents" | "layouts" | "schedule" | "screens" | "rooms" | "protocol" | "alerts" | "tasks" | "reports" | "admin" | "player" | "assumptions"

type IconName = "home" | "file" | "image" | "folder" | "layout" | "calendar" | "monitor" | "door" | "users" | "alert" | "check" | "chart" | "settings" | "play" | "info" | "search" | "bell" | "plus" | "arrow" | "more" | "filter" | "download" | "clock" | "wifi" | "upload" | "eye" | "shield" | "x" | "chevron" | "edit" | "building" | "sun"

const iconPaths: Record<IconName, React.ReactNode> = {
  home: (
    <>
      <path d="m3 11 9-8 9 8" />
      <path d="M5 10v10h14V10M9 20v-6h6v6" />
    </>
  ),
  file: (
    <>
      <path d="M6 2h8l4 4v16H6z" />
      <path d="M14 2v5h5M9 13h6M9 17h6" />
    </>
  ),
  image: (
    <>
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <circle cx="9" cy="10" r="2" />
      <path d="m3 17 5-5 4 4 3-3 6 6" />
    </>
  ),
  folder: (
    <>
      <path d="M3 6h7l2 2h9v11H3z" />
    </>
  ),
  layout: (
    <>
      <rect x="3" y="3" width="18" height="18" rx="2" />
      <path d="M3 9h18M9 9v12" />
    </>
  ),
  calendar: (
    <>
      <rect x="3" y="5" width="18" height="16" rx="2" />
      <path d="M7 3v4M17 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01" />
    </>
  ),
  monitor: (
    <>
      <rect x="2" y="3" width="20" height="14" rx="2" />
      <path d="M8 21h8M12 17v4" />
    </>
  ),
  door: (
    <>
      <path d="M5 21V3h14v18M9 21V7h7v14" />
      <path d="M13 14h.01" />
    </>
  ),
  users: (
    <>
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
    </>
  ),
  alert: (
    <>
      <path d="M10.3 3.8 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0Z" />
      <path d="M12 9v4M12 17h.01" />
    </>
  ),
  check: (
    <>
      <path d="m20 6-11 11-5-5" />
    </>
  ),
  chart: (
    <>
      <path d="M4 19V9M10 19V5M16 19v-7M22 19V2" />
      <path d="M2 19h21" />
    </>
  ),
  settings: (
    <>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z" />
    </>
  ),
  play: (
    <>
      <path d="m8 5 11 7-11 7z" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="10" />
      <path d="M12 11v6M12 7h.01" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-4-4" />
    </>
  ),
  bell: (
    <>
      <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
    </>
  ),
  plus: (
    <>
      <path d="M12 5v14M5 12h14" />
    </>
  ),
  arrow: (
    <>
      <path d="M5 12h14M13 6l6 6-6 6" />
    </>
  ),
  more: (
    <>
      <circle cx="5" cy="12" r="1" />
      <circle cx="12" cy="12" r="1" />
      <circle cx="19" cy="12" r="1" />
    </>
  ),
  filter: (
    <>
      <path d="M4 5h16M7 12h10M10 19h4" />
    </>
  ),
  download: (
    <>
      <path d="M12 3v12m0 0 5-5m-5 5-5-5M4 21h16" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
  wifi: (
    <>
      <path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01M2 9a14 14 0 0 1 20 0" />
    </>
  ),
  upload: (
    <>
      <path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" />
    </>
  ),
  eye: (
    <>
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  shield: (
    <>
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
      <path d="m9 12 2 2 4-4" />
    </>
  ),
  x: (
    <>
      <path d="m6 6 12 12M18 6 6 18" />
    </>
  ),
  chevron: (
    <>
      <path d="m9 18 6-6-6-6" />
    </>
  ),
  edit: (
    <>
      <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" />
    </>
  ),
  building: (
    <>
      <path d="M4 21V4h10v17M14 9h6v12M8 8h2M8 12h2M8 16h2M17 13h.01M17 17h.01M2 21h20" />
    </>
  ),
  sun: (
    <>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" />
    </>
  ),
}

function Icon({ name, size = 18 }: { name: IconName size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {iconPaths[name]}
    </svg>
  )
}

const nav: {
  group: string
  items: { id: Page label: string icon: IconName count?: number }[]
}[] = [
  {
    group: "Vue générale",
    items: [
      { id: "dashboard", label: "Tableau de bord", icon: "home" },
      { id: "tasks", label: "Tâches & validations", icon: "check", count: 7 },
    ],
  },
  {
    group: "Publication",
    items: [
      { id: "contents", label: "Contenus", icon: "file" },
      { id: "media", label: "Médiathèque", icon: "image" },
      { id: "documents", label: "Documents", icon: "folder" },
      { id: "layouts", label: "Modèles", icon: "layout" },
      { id: "schedule", label: "Programmation", icon: "calendar" },
    ],
  },
  {
    group: "Espaces",
    items: [
      { id: "screens", label: "Écrans & lecteurs", icon: "monitor" },
      { id: "rooms", label: "Salles & réunions", icon: "door" },
      { id: "protocol", label: "Accueil & protocole", icon: "users" },
      { id: "alerts", label: "Alertes urgentes", icon: "alert" },
    ],
  },
  {
    group: "Pilotage",
    items: [
      { id: "reports", label: "Rapports", icon: "chart" },
      { id: "admin", label: "Administration", icon: "settings" },
      { id: "player", label: "Vues de diffusion", icon: "play" },
      { id: "assumptions", label: "Cadrage & couverture", icon: "info" },
    ],
  },
]

const pageTitles: Record<Page, [string, string]> = {
  dashboard: [
    "Bonjour, Nadia",
    "Voici la situation du SAIE-CEEAC aujourd’hui.",
  ],
  contents: [
    "Contenus institutionnels",
    "Créez, validez et suivez les informations diffusées.",
  ],
  media: ["Médiathèque", "Gérez les médias sains et leurs utilisations."],
  documents: [
    "Gestion documentaire",
    "Documents institutionnels, versions et accès.",
  ],
  layouts: [
    "Modèles d’affichage",
    "Composez les expériences paysage et portrait.",
  ],
  schedule: [
    "Programmation & diffusion",
    "Planifiez et suivez la preuve technique de diffusion.",
  ],
  screens: ["Écrans & lecteurs", "Supervisez le parc du siège de Libreville."],
  rooms: ["Salles & réunions", "Trouvez un créneau et préparez les réunions."],
  protocol: [
    "Accueil & protocole",
    "Préparez l’accueil des visites et délégations.",
  ],
  alerts: [
    "Centre des alertes",
    "Déclenchez et suivez les messages prioritaires.",
  ],
  tasks: ["Tâches & validations", "Les actions qui attendent votre décision."],
  reports: [
    "Rapports & statistiques",
    "Mesurez l’activité sans surévaluer les preuves.",
  ],
  admin: ["Administration", "Référentiels, habilitations et journalisation."],
  player: [
    "Vues de diffusion",
    "Aperçus des lecteurs, panneaux et états de continuité.",
  ],
  assumptions: [
    "Cadrage & couverture",
    "Décisions, hypothèses et traçabilité des exigences.",
  ],
}

const Status = ({
  children,
  tone = "neutral",
}: {
  children: React.ReactNode
  tone?: string
}) => (
  <span className={`status ${tone}`}>
    <span className="status-dot" />
    {children}
  </span>
)

function Button({
  children,
  icon,
  variant = "primary",
  onClick,
  disabled = false,
}: {
  children: React.ReactNode
  icon?: IconName
  variant?: string
  onClick?: () => void
  disabled?: boolean
}) {
  return (
    <button
      className={`button ${variant}`}
      onClick={onClick}
      disabled={disabled}
    >
      {icon && <Icon name={icon} size={16} />}
      <span>{children}</span>
    </button>
  )
}

function DemoNote() {
  return (
    <div className="demo-note">
      <Icon name="info" size={15} />
      <span>Données fictives de démonstration — site de Libreville</span>
    </div>
  )
}

function Toolbar({
  placeholder = "Rechercher…",
  right,
}: {
  placeholder?: string
  right?: React.ReactNode
}) {
  return (
    <div className="toolbar">
      <label className="search-field">
        <Icon name="search" size={17} />
        <input placeholder={placeholder} />
        <kbd>⌘ K</kbd>
      </label>
      <Button variant="secondary" icon="filter">
        Filtres
      </Button>
      <div className="toolbar-spacer" />
      {right}
    </div>
  )
}

function EmptyState({ title, text }: { title: string text: string }) {
  return (
    <div className="empty-state">
      <div className="empty-icon">
        <Icon name="search" size={23} />
      </div>
      <h3>{title}</h3>
      <p>{text}</p>
      <Button variant="secondary">Réinitialiser les filtres</Button>
    </div>
  )
}

function Dashboard({ go }: { go: (p: Page) => void }) {
  const stats = [
    {
      icon: "monitor" as IconName,
      value: "12",
      total: "/ 14",
      label: "Écrans connectés",
      delta: "2 hors ligne",
      tone: "blue",
      page: "screens" as Page,
    },
    {
      icon: "play" as IconName,
      value: "5",
      total: "",
      label: "Diffusions actives",
      delta: "3 à venir",
      tone: "green",
      page: "schedule" as Page,
    },
    {
      icon: "check" as IconName,
      value: "7",
      total: "",
      label: "À valider",
      delta: "2 échéances proches",
      tone: "amber",
      page: "tasks" as Page,
    },
    {
      icon: "door" as IconName,
      value: "3",
      total: "/ 4",
      label: "Salles disponibles",
      delta: "6 réunions aujourd’hui",
      tone: "violet",
      page: "rooms" as Page,
    },
  ]
  return (
    <>
      <div className="page-heading">
        <div>
          <h1>{pageTitles.dashboard[0]}</h1>
          <p>{pageTitles.dashboard[1]}</p>
        </div>
        <div className="heading-actions">
          <Button variant="secondary" icon="calendar">
            13 nov. 2026
          </Button>
          <Button icon="plus" onClick={() => go("contents")}>
            Créer un contenu
          </Button>
        </div>
      </div>
      <DemoNote />
      <div className="stat-grid">
        {stats.map((s) => (
          <button
            className="stat-card"
            key={s.label}
            onClick={() => go(s.page)}
          >
            <div className={`stat-icon ${s.tone}`}>
              <Icon name={s.icon} />
            </div>
            <div className="stat-top">
              <strong>{s.value}</strong>
              <span>{s.total}</span>
              <Icon name="arrow" size={17} />
            </div>
            <p>{s.label}</p>
            <small>{s.delta}</small>
          </button>
        ))}
      </div>
      <div className="dashboard-grid">
        <section className="panel span-2">
          <div className="panel-title">
            <div>
              <h2>État du parc par niveau</h2>
              <p>Dernier contact vérifié à 10:42</p>
            </div>
            <button className="link" onClick={() => go("screens")}>
              Voir le parc <Icon name="arrow" size={15} />
            </button>
          </div>
          <div className="building-overview">
            {[
              "6e étage",
              "5e étage",
              "4e étage",
              "3e étage",
              "2e étage",
              "1er étage",
              "RDC",
            ].map((floor, i) => (
              <div className="floor-row" key={floor}>
                <span>{floor}</span>
                <div className="floor-track">
                  <i
                    className={i === 4 ? "warn" : ""}
                    style={{ width: i === 4 ? "50%" : "100%" }}
                  />
                </div>
                <strong>{i === 4 ? "1/2" : "2/2"}</strong>
                {i === 4 ? (
                  <Status tone="warning">À vérifier</Status>
                ) : (
                  <Status tone="success">Opérationnel</Status>
                )}
              </div>
            ))}
          </div>
        </section>
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Actions attendues</h2>
              <p>7 tâches vous sont assignées</p>
            </div>
            <button className="icon-button" onClick={() => go("tasks")}>
              <Icon name="arrow" />
            </button>
          </div>
          <div className="task-list">
            <button onClick={() => go("tasks")}>
              <span className="task-symbol amber">
                <Icon name="file" />
              </span>
              <span>
                <strong>Communiqué du Secrétariat</strong>
                <small>Validation FR · échéance dans 4 h</small>
              </span>
              <Icon name="chevron" size={16} />
            </button>
            <button onClick={() => go("tasks")}>
              <span className="task-symbol blue">
                <Icon name="door" />
              </span>
              <span>
                <strong>Réservation Salle Kivu</strong>
                <small>Approbation · depuis 2 h</small>
              </span>
              <Icon name="chevron" size={16} />
            </button>
            <button onClick={() => go("tasks")}>
              <span className="task-symbol red">
                <Icon name="alert" />
              </span>
              <span>
                <strong>Incident ECR-2E-02</strong>
                <small>Assigné à la DSI · critique</small>
              </span>
              <Icon name="chevron" size={16} />
            </button>
          </div>
          <button className="full-link" onClick={() => go("tasks")}>
            Afficher les 7 tâches
          </button>
        </section>
        <section className="panel span-2">
          <div className="panel-title">
            <div>
              <h2>Diffusions du jour</h2>
              <p>Fuseau Africa/Libreville (UTC+1)</p>
            </div>
            <button className="link" onClick={() => go("schedule")}>
              Ouvrir le calendrier
            </button>
          </div>
          <div className="timeline">
            <div className="time-labels">
              <span>08:00</span>
              <span>10:00</span>
              <span>12:00</span>
              <span>14:00</span>
              <span>16:00</span>
              <span>18:00</span>
            </div>
            <div className="timeline-row">
              <b>Accueil RDC</b>
              <span
                className="event blue-event"
                style={{ left: "13%", width: "32%" }}
              >
                Bienvenue délégation CEMAC
              </span>
              <span
                className="event green-event"
                style={{ left: "47%", width: "38%" }}
              >
                Campagne institutionnelle
              </span>
            </div>
            <div className="timeline-row">
              <b>Étages 1–6</b>
              <span
                className="event green-event"
                style={{ left: "8%", width: "62%" }}
              >
                Communication interne · FR / EN
              </span>
              <span
                className="event gray-event"
                style={{ left: "72%", width: "18%" }}
              >
                Agenda
              </span>
            </div>
          </div>
        </section>
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Réunions à venir</h2>
              <p>Aujourd’hui</p>
            </div>
            <button className="link" onClick={() => go("rooms")}>
              Toutes
            </button>
          </div>
          <div className="meeting-list">
            <div>
              <time>10:30</time>
              <span>
                <strong>Comité de pilotage SAIE</strong>
                <small>Salle Kivu · 12 participants</small>
              </span>
            </div>
            <div>
              <time>13:00</time>
              <span>
                <strong>Réservée</strong>
                <small>Salle Ogooué · confidentielle</small>
              </span>
            </div>
            <div>
              <time>15:30</time>
              <span>
                <strong>Revue budgétaire</strong>
                <small>Salle Congo · 32 participants</small>
              </span>
            </div>
          </div>
        </section>
        <section className="panel span-2">
          <div className="panel-title">
            <div>
              <h2>Suivi des projets institutionnels</h2>
              <p>Indicateurs déclaratifs autorisés à la diffusion interne</p>
            </div>
            <button className="link" onClick={() => go("reports")}>
              Voir les indicateurs <Icon name="arrow" size={15} />
            </button>
          </div>
          <div className="project-list">
            {[
              ["Déploiement pilote SAIE", "72%", "Dans les délais", "success"],
              [
                "Programme de formation interne",
                "46%",
                "8 sessions à venir",
                "blue",
              ],
              [
                "Préparation du sommet régional",
                "88%",
                "2 actions à risque",
                "warning",
              ],
            ].map((project) => (
              <button key={project[0]} onClick={() => go("reports")}>
                <span>
                  <strong>{project[0]}</strong>
                  <small>{project[2]}</small>
                </span>
                <i>
                  <b style={{ width: project[1] }} />
                </i>
                <em>{project[1]}</em>
                <Status tone={project[3]}>{project[2]}</Status>
              </button>
            ))}
          </div>
        </section>
        <section className="panel practical-panel">
          <div className="panel-title">
            <div>
              <h2>Informations pratiques</h2>
              <p>Libreville · données actualisées à 10:40</p>
            </div>
          </div>
          <div className="weather-now">
            <Icon name="sun" size={30} />
            <strong>28°</strong>
            <span>
              Éclaircies<small>Ressenti 31° · humidité 78%</small>
            </span>
          </div>
          <div className="practical-list">
            <span>
              <Icon name="clock" />
              <b>10:42</b> Africa/Libreville
            </span>
            <span>
              <Icon name="info" />
              <b>Accès Est fermé</b> jusqu’à 12:00
            </span>
            <span>
              <Icon name="shield" />
              <b>Consigne</b> garder les issues dégagées
            </span>
          </div>
        </section>
      </div>
    </>
  )
}

const contentRows = [
  [
    "Bienvenue à la délégation CEMAC",
    "Accueil des délégations",
    "FR · EN",
    "En diffusion",
    "success",
    "13–14 nov.",
  ],
  [
    "Communiqué du Secrétariat général",
    "Communiqué",
    "FR · EN",
    "En attente",
    "warning",
    "Jusqu’au 21 nov.",
  ],
  [
    "Campagne santé au travail",
    "Information RH",
    "FR · EN",
    "Programmé",
    "blue",
    "18–30 nov.",
  ],
  [
    "Consignes d’évacuation",
    "Consigne pratique",
    "FR",
    "À corriger",
    "danger",
    "Permanent",
  ],
  [
    "Journée de la CEEAC",
    "Annonce",
    "FR · EN",
    "Brouillon",
    "neutral",
    "À définir",
  ],
  [
    "Maintenance réseau planifiée",
    "Information DSI",
    "FR",
    "Expiré",
    "neutral",
    "12 nov.",
  ],
  [
    "Message de la Présidence de la Commission",
    "Direction générale",
    "FR · EN",
    "Programmé",
    "blue",
    "16–20 nov.",
  ],
  [
    "Formation aux outils collaboratifs",
    "Formation RH",
    "FR",
    "En attente",
    "warning",
    "24 nov.",
  ],
  [
    "Note de service — horaires de fin d’année",
    "Note de service RH",
    "FR · EN",
    "En diffusion",
    "success",
    "13–30 nov.",
  ],
  [
    "Annonce officielle — session statutaire",
    "Annonce officielle",
    "FR · EN",
    "Brouillon",
    "neutral",
    "À définir",
  ],
]

function Contents({ openFlow }: { openFlow: (type: string) => void }) {
  const [filter, setFilter] = useState("Tous")
  const rows =
    filter === "Tous" ? contentRows : contentRows.filter((r) => r[3] === filter)
  return (
    <>
      <PageHeading
        page="contents"
        action={
          <Button icon="plus" onClick={() => openFlow("content")}>
            Nouveau contenu
          </Button>
        }
      />
      <div className="tabs">
        {[
          "Tous",
          "Brouillon",
          "En attente",
          "Programmé",
          "En diffusion",
          "À corriger",
          "Archivé",
        ].map((t) => (
          <button
            key={t}
            className={filter === t ? "active" : ""}
            onClick={() => setFilter(t)}
          >
            {t}
            {t === "En attente" && <span>3</span>}
          </button>
        ))}
      </div>
      <section className="panel table-panel">
        <Toolbar
          placeholder="Rechercher par titre, auteur ou catégorie…"
          right={
            <Button variant="secondary" icon="download">
              Exporter
            </Button>
          }
        />
        {rows.length ? (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>
                    <input type="checkbox" />
                  </th>
                  <th>Contenu</th>
                  <th>Catégorie</th>
                  <th>Langues</th>
                  <th>Statut</th>
                  <th>Validité</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r[0]}>
                    <td>
                      <input type="checkbox" />
                    </td>
                    <td>
                      <div className="title-cell">
                        <span className={`doc-thumb t${i % 3}`}>
                          <Icon name={i % 2 ? "file" : "image"} size={17} />
                        </span>
                        <span>
                          <strong>{r[0]}</strong>
                          <small>
                            SAIE-CNT-{String(77 - i).padStart(3, "0")} · v
                            {2 + (i % 2)}
                          </small>
                        </span>
                      </div>
                    </td>
                    <td>{r[1]}</td>
                    <td>
                      <span className="lang">{r[2]}</span>
                    </td>
                    <td>
                      <Status tone={r[4]}>{r[3]}</Status>
                    </td>
                    <td>{r[5]}</td>
                    <td>
                      <button className="icon-button">
                        <Icon name="more" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState
            title="Aucun contenu trouvé"
            text="Aucun résultat ne correspond à ce statut et à vos filtres."
          />
        )}
        <Pagination count={`1–${rows.length} sur ${contentRows.length}`} />
      </section>
    </>
  )
}

function Media({ openFlow }: { openFlow: (type: string) => void }) {
  const media = [
    [
      "delegation-cemac.jpg",
      "Image · 2400 × 1600",
      "2,8 Mo",
      "Prêt",
      "success",
      "photo",
    ],
    [
      "message-president.mp4",
      "Vidéo · 01:42 · H.264",
      "86,4 Mo",
      "Prêt",
      "success",
      "video",
    ],
    [
      "programme-session.pdf",
      "PDF · 18 pages",
      "4,2 Mo",
      "Prêt",
      "success",
      "pdf",
    ],
    [
      "charte-graphique.pdf",
      "PDF · 32 pages",
      "12,7 Mo",
      "Analyse",
      "warning",
      "pdf",
    ],
    [
      "hall-siege.webp",
      "Image · 1920 × 1080",
      "1,6 Mo",
      "Prêt",
      "success",
      "photo",
    ],
    [
      "interview-novembre.mp4",
      "Vidéo · 03:12",
      "184 Mo",
      "Rejeté",
      "danger",
      "video",
    ],
    [
      "transition-ceeac.webm",
      "Animation · 00:08 · WebM",
      "3,8 Mo",
      "Prêt",
      "success",
      "animation",
    ],
  ]
  return (
    <>
      <PageHeading
        page="media"
        action={
          <Button icon="upload" onClick={() => openFlow("pdf")}>
            Importer des fichiers
          </Button>
        }
      />
      <section className="panel">
        <Toolbar
          placeholder="Rechercher dans la médiathèque…"
          right={
            <div className="view-switch">
              <button className="active">
                <Icon name="layout" />
              </button>
              <button>
                <Icon name="file" />
              </button>
            </div>
          }
        />
        <div className="media-grid">
          {media.map((m, i) => (
            <article className="media-card" key={m[0]}>
              <div className={`media-preview ${m[5]}`}>
                <Icon
                  name={
                    m[5] === "photo"
                      ? "image"
                      : m[5] === "video" || m[5] === "animation"
                        ? "play"
                        : "file"
                  }
                  size={32}
                />
                {(m[5] === "video" || m[5] === "animation") && (
                  <span className="duration">01:{i ? "42" : "18"}</span>
                )}
              </div>
              <div className="media-body">
                <div>
                  <strong>{m[0]}</strong>
                  <button>
                    <Icon name="more" />
                  </button>
                </div>
                <small>
                  {m[1]} · {m[2]}
                </small>
                <div className="media-foot">
                  <Status tone={m[4]}>{m[3]}</Status>
                  <span>
                    {i + 1} utilisation{i ? "s" : ""}
                  </span>
                </div>
              </div>
            </article>
          ))}
        </div>
        <Pagination count="1–7 sur 29" />
      </section>
    </>
  )
}

function Documents({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <>
      <PageHeading
        page="documents"
        action={
          <Button icon="upload" onClick={() => openFlow("pdf")}>
            Déposer un document
          </Button>
        }
      />
      <div className="mini-stats">
        <div>
          <Icon name="folder" />
          <span>
            <strong>12</strong> documents actifs
          </span>
        </div>
        <div>
          <Icon name="shield" />
          <span>
            <strong>7</strong> internes
          </span>
        </div>
        <div>
          <Icon name="clock" />
          <span>
            <strong>2</strong> expirent bientôt
          </span>
        </div>
        <div>
          <Icon name="upload" />
          <span>
            <strong>1</strong> en analyse
          </span>
        </div>
      </div>
      <section className="panel table-panel">
        <Toolbar placeholder="Rechercher dans les métadonnées et les PDF…" />
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Référence & titre</th>
                <th>Propriétaire</th>
                <th>Classification</th>
                <th>Version</th>
                <th>Mis à jour</th>
                <th>Diffusion</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {[
                [
                  "DOC-2026-041",
                  "Note de service — horaires de fin d’année",
                  "Secrétariat général",
                  "Interne",
                  "v3",
                  "12 nov. 2026",
                  "Autorisée",
                ],
                [
                  "DOC-2026-038",
                  "Rapport annuel d’activités 2025",
                  "Communication",
                  "Public",
                  "v2",
                  "08 nov. 2026",
                  "Autorisée",
                ],
                [
                  "DOC-2026-034",
                  "Procédure de continuité informatique",
                  "DSI",
                  "Restreint",
                  "v1",
                  "04 nov. 2026",
                  "Interdite",
                ],
                [
                  "DOC-2026-029",
                  "Guide pratique des salles",
                  "Moyens généraux",
                  "Interne",
                  "v4",
                  "28 oct. 2026",
                  "Autorisée",
                ],
              ].map((r, i) => (
                <tr key={r[0]}>
                  <td>
                    <div className="title-cell">
                      <span className="doc-thumb">
                        <Icon name="file" />
                      </span>
                      <span>
                        <strong>{r[1]}</strong>
                        <small>{r[0]}</small>
                      </span>
                    </div>
                  </td>
                  <td>{r[2]}</td>
                  <td>
                    <Status
                      tone={
                        r[3] === "Public"
                          ? "success"
                          : r[3] === "Restreint"
                            ? "danger"
                            : "blue"
                      }
                    >
                      {r[3]}
                    </Status>
                  </td>
                  <td>{r[4]}</td>
                  <td>{r[5]}</td>
                  <td>
                    <span
                      className={
                        r[6] === "Interdite" ? "red-text" : "green-text"
                      }
                    >
                      {r[6]}
                    </span>
                  </td>
                  <td>
                    <button className="icon-button">
                      <Icon name="more" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination count="1–4 sur 12" />
      </section>
    </>
  )
}

function Layouts() {
  const [orientation, setOrientation] = useState("Paysage")
  return (
    <>
      <PageHeading
        page="layouts"
        action={<Button icon="plus">Nouveau modèle</Button>}
      />
      <div className="tabs">
        <button
          className={orientation === "Paysage" ? "active" : ""}
          onClick={() => setOrientation("Paysage")}
        >
          Paysage 16:9
        </button>
        <button
          className={orientation === "Portrait" ? "active" : ""}
          onClick={() => setOrientation("Portrait")}
        >
          Portrait 9:16
        </button>
        <button>Archivés</button>
      </div>
      <div className="layout-workspace">
        <section className="panel block-library">
          <h2>Blocs disponibles</h2>
          <p>Glissez un bloc vers la composition</p>
          {[
            "En-tête institutionnel",
            "Texte & média",
            "Vidéo",
            "Agenda du jour",
            "Bandeau défilant",
            "Horloge & date",
            "QR code",
          ].map((x, i) => (
            <button key={x}>
              <span>
                <Icon
                  name={
                    [
                      "layout",
                      "file",
                      "play",
                      "calendar",
                      "arrow",
                      "clock",
                      "image",
                    ][i] as IconName
                  }
                />
              </span>
              {x}
              <Icon name="plus" size={15} />
            </button>
          ))}
        </section>
        <section className="panel canvas-panel">
          <div className="panel-title">
            <div>
              <h2>Accueil institutionnel</h2>
              <p>Version 4 · brouillon</p>
            </div>
            <div className="zoom">75%</div>
          </div>
          <div
            className={`designer-canvas ${
              orientation === "Portrait" ? "portrait" : ""
            }`}
          >
            <div className="design-head">
              <img src="/ceeac-logo.jpg" />
              <b>SAIE–CEEAC</b>
              <span>10:42 · 13 novembre</span>
            </div>
            <div className="design-main">
              <div>
                <small>COMMUNIQUÉ</small>
                <h3>Au service de l’intégration régionale</h3>
                <p>Une information institutionnelle claire et accessible.</p>
              </div>
              <div className="design-image">
                <Icon name="image" size={40} />
              </div>
            </div>
            <div className="design-ticker">
              Actualités de la Commission · Réunions du jour · Informations
              pratiques
            </div>
          </div>
        </section>
        <section className="panel properties">
          <h2>Propriétés du bloc</h2>
          <label>
            Type de bloc
            <input value="Texte & média" readOnly />
          </label>
          <label>
            Durée d’affichage
            <div className="input-suffix">
              <input value="12" readOnly />
              <span>sec.</span>
            </div>
          </label>
          <label>
            Transition
            <select>
              <option>Fondu doux</option>
            </select>
          </label>
          <label>
            Disposition
            <select>
              <option>Texte à gauche</option>
            </select>
          </label>
          <hr />
          <Button variant="secondary" icon="eye">
            Prévisualiser
          </Button>
          <Button>Enregistrer le modèle</Button>
        </section>
      </div>
    </>
  )
}

function Schedule() {
  return (
    <>
      <PageHeading
        page="schedule"
        action={<Button icon="plus">Programmer une campagne</Button>}
      />
      <div className="tabs">
        <button className="active">Calendrier</button>
        <button>Listes de lecture</button>
        <button>Suivi de diffusion</button>
        <button>Contenus de repli</button>
      </div>
      <section className="panel schedule-panel">
        <div className="calendar-top">
          <div className="date-nav">
            <button>
              <Icon name="chevron" size={16} />
            </button>
            <h2>13 novembre 2026</h2>
            <button>
              <Icon name="chevron" size={16} />
            </button>
          </div>
          <div>
            <Button variant="secondary">Aujourd’hui</Button>
            <select>
              <option>Vue par écran</option>
            </select>
          </div>
        </div>
        <div className="proof-legend">
          <span>
            <i className="scheduled" />
            Programmé
          </span>
          <span>
            <i className="received" />
            Manifeste reçu
          </span>
          <span>
            <i className="loaded" />
            Téléchargé
          </span>
          <span>
            <i className="active" />
            Lecture déclarée
          </span>
          <b>
            <Icon name="info" size={14} /> Ne prouve pas qu’une personne a vu le
            contenu.
          </b>
        </div>
        <div className="calendar-grid">
          <div className="cal-head">
            <span>Écran / groupe</span>
            {["08:00", "10:00", "12:00", "14:00", "16:00", "18:00"].map((x) => (
              <span key={x}>{x}</span>
            ))}
          </div>
          {[
            "Accueil • RDC",
            "Écrans • 1er étage",
            "Écrans • 2e étage",
            "Écrans • 3e–6e",
          ].map((x, i) => (
            <div className="cal-row" key={x}>
              <strong>
                {x}
                <small>{i === 2 ? "1 lecteur hors ligne" : "2 écrans"}</small>
              </strong>
              <div className={`cal-event c${i}`}>
                <b>
                  {i === 0
                    ? "Bienvenue délégation"
                    : i === 2
                      ? "Information RH"
                      : "Communication interne"}
                </b>
                <small>
                  {i === 0 ? "09:00–12:30" : "08:00–17:45"} · FR / EN
                </small>
              </div>
              {i === 2 && <span className="conflict">Conflit priorité 50</span>}
            </div>
          ))}
        </div>
      </section>
    </>
  )
}

function Screens({ openFlow }: { openFlow: (type: string) => void }) {
  const floors = ["6", "5", "4", "3", "2", "1", "RDC"]
  return (
    <>
      <PageHeading
        page="screens"
        action={
          <Button icon="plus" onClick={() => openFlow("device")}>
            Enrôler un lecteur
          </Button>
        }
      />
      <div className="screen-summary">
        <div>
          <strong>14</strong>
          <span>écrans inventoriés</span>
        </div>
        <div>
          <strong className="green-text">12</strong>
          <span>connectés</span>
        </div>
        <div>
          <strong className="amber-text">1</strong>
          <span>suspect</span>
        </div>
        <div>
          <strong className="red-text">1</strong>
          <span>indisponible</span>
        </div>
        <div>
          <strong>82%</strong>
          <span>stockage moyen libre</span>
        </div>
      </div>
      <div className="split-view">
        <section className="panel building-panel">
          <div className="panel-title">
            <div>
              <h2>Synoptique du siège</h2>
              <p>Représentation schématique · aucun plan architectural</p>
            </div>
            <Status tone="success">12 / 14 en ligne</Status>
          </div>
          <div className="tower">
            {floors.map((f, i) => (
              <button key={f} className={i === 4 ? "issue" : ""}>
                <span>{f === "RDC" ? "RDC" : `${f}e`}</span>
                <div>
                  <i />
                  <i className={i === 4 ? "offline" : ""} />
                </div>
                <strong>{i === 4 ? "1 / 2" : "2 / 2"}</strong>
                <Icon name="chevron" size={15} />
              </button>
            ))}
          </div>
        </section>
        <section className="panel device-panel">
          <div className="panel-title">
            <div>
              <h2>Lecteurs à surveiller</h2>
              <p>État réseau distinct de l’état de dalle</p>
            </div>
            <button className="link">Voir les 14</button>
          </div>
          {[
            [
              "ECR-2E-02",
              "2e étage · Couloir Est",
              "Indisponible",
              "danger",
              "Dernier contact il y a 18 min",
              "Non mesuré",
            ],
            [
              "ECR-RDC-01",
              "RDC · Hall principal",
              "Connecté",
              "success",
              "Synchronisé il y a 12 s",
              "Allumée",
            ],
            [
              "ECR-4E-02",
              "4e étage · Ascenseurs",
              "Cache 88%",
              "warning",
              "Manifeste v412 actif",
              "Non mesuré",
            ],
            [
              "ECR-6E-01",
              "6e étage · Accueil",
              "Connecté",
              "success",
              "Synchronisé il y a 24 s",
              "Allumée",
            ],
          ].map((r) => (
            <button className="device-row" key={r[0]}>
              <span className="screen-icon">
                <Icon name="monitor" />
              </span>
              <span>
                <strong>{r[0]}</strong>
                <small>{r[1]}</small>
              </span>
              <span>
                <Status tone={r[3]}>{r[2]}</Status>
                <small>{r[4]}</small>
              </span>
              <span>
                <b>Dalle</b>
                <small>{r[5]}</small>
              </span>
              <Icon name="chevron" size={16} />
            </button>
          ))}
        </section>
      </div>
    </>
  )
}

function Rooms({ openFlow }: { openFlow: (type: string) => void }) {
  const [view, setView] = useState("Catalogue")
  const rooms: Array<{
    name: string
    floor: string
    capacity: number
    availability: string
    tone: string
    equipment: string[]
  }> = [
    {
      name: "Salle Kivu",
      floor: "1er étage",
      capacity: 12,
      availability: "Libre jusqu’à 13:00",
      tone: "success",
      equipment: ["Visioconférence", 'Écran 65"'],
    },
    {
      name: "Salle Ogooué",
      floor: "3e étage",
      capacity: 20,
      availability: "Occupée jusqu’à 14:30",
      tone: "warning",
      equipment: ["Visioconférence", "Interprétation"],
    },
    {
      name: "Salle Congo",
      floor: "6e étage",
      capacity: 40,
      availability: "Libre toute la journée",
      tone: "success",
      equipment: ["Sonorisation", 'Écran 85"'],
    },
    {
      name: "Salle CEEAC",
      floor: "RDC",
      capacity: 100,
      availability: "Indisponible",
      tone: "danger",
      equipment: ["Cabines traduction", "Régie audiovisuelle"],
    },
  ]
  return (
    <>
      <PageHeading
        page="rooms"
        action={
          <Button icon="plus" onClick={() => openFlow("room")}>
            Nouvelle réservation
          </Button>
        }
      />
      <div className="tabs">
        <button
          className={view === "Catalogue" ? "active" : ""}
          onClick={() => setView("Catalogue")}
        >
          Catalogue
        </button>
        <button
          className={view === "Calendrier" ? "active" : ""}
          onClick={() => setView("Calendrier")}
        >
          Calendrier
        </button>
        <button
          className={view === "Agenda & événements" ? "active" : ""}
          onClick={() => setView("Agenda & événements")}
        >
          Agenda & événements
        </button>
        <button
          className={view === "À valider" ? "active" : ""}
          onClick={() => setView("À valider")}
        >
          À valider <span>2</span>
        </button>
      </div>
      {view === "Catalogue" && (
        <section className="panel">
          <Toolbar placeholder="Nom de salle, équipement ou capacité…" />
          <div className="room-grid">
            {rooms.map((room, i) => (
              <article className="room-card" key={room.name}>
                <div className={`room-visual rv${i}`}>
                  <Icon name="door" size={30} />
                  <Status tone={room.tone}>{room.availability}</Status>
                </div>
                <div>
                  <small>
                    {room.floor} · {room.capacity} personnes
                  </small>
                  <h3>{room.name}</h3>
                  <div className="chips">
                    {room.equipment.map((x) => (
                      <span key={x}>{x}</span>
                    ))}
                  </div>
                  <button onClick={() => openFlow("room")} disabled={i === 3}>
                    {i === 3
                      ? "Voir l’indisponibilité"
                      : "Réserver cette salle"}
                    <Icon name="arrow" size={15} />
                  </button>
                </div>
              </article>
            ))}
          </div>
        </section>
      )}
      {view === "Calendrier" && <RoomCalendar />}
      {view === "Agenda & événements" && <MeetingAgendas />}
      {view === "À valider" && <RoomApprovals openFlow={openFlow} />}
    </>
  )
}

function RoomCalendar() {
  return (
    <section className="panel room-calendar-panel">
      <div className="calendar-top">
        <div className="date-nav">
          <button>
            <Icon name="chevron" size={16} />
          </button>
          <h2>Semaine du 16 au 20 novembre 2026</h2>
          <button>
            <Icon name="chevron" size={16} />
          </button>
        </div>
        <div>
          <Button variant="secondary">Aujourd’hui</Button>
          <select>
            <option>Vue semaine</option>
            <option>Vue jour</option>
            <option>Vue mois</option>
          </select>
        </div>
      </div>
      <div className="meeting-calendar">
        <div className="meeting-calendar-head">
          <span>Salles</span>
          {["Lun. 16", "Mar. 17", "Mer. 18", "Jeu. 19", "Ven. 20"].map(
            (day) => (
              <span key={day}>{day}</span>
            ),
          )}
        </div>
        {[
          [
            "Salle Kivu",
            "Atelier SAIE",
            "Formation RH",
            "Libre",
            "Comité éditorial",
            "Libre",
          ],
          [
            "Salle Ogooué",
            "Réservée",
            "Réunion DSI",
            "Libre",
            "Mission BAD",
            "Libre",
          ],
          [
            "Salle Congo",
            "Coordination régionale",
            "Libre",
            "Session statutaire",
            "Session statutaire",
            "Revue projets",
          ],
          [
            "Salle CEEAC",
            "Indisponible",
            "Indisponible",
            "Conseil de direction",
            "Libre",
            "Événement institutionnel",
          ],
        ].map((row) => (
          <div className="meeting-calendar-row" key={row[0]}>
            <strong>{row[0]}</strong>
            {row.slice(1).map((item, index) => (
              <button
                className={
                  item === "Libre"
                    ? "free"
                    : item === "Indisponible"
                      ? "unavailable"
                      : "booked"
                }
                key={`${item}-${index}`}
              >
                {item}
                <small>
                  {item === "Libre"
                    ? "Disponible"
                    : item === "Indisponible"
                      ? "Maintenance"
                      : "10:00–12:00"}
                </small>
              </button>
            ))}
          </div>
        ))}
      </div>
    </section>
  )
}

function MeetingAgendas() {
  return (
    <div className="two-columns meeting-agenda-layout">
      <section className="panel">
        <div className="panel-title">
          <div>
            <h2>Réunions et événements</h2>
            <p>Objets autorisés selon votre périmètre</p>
          </div>
          <Button variant="secondary" icon="plus">
            Nouvel événement
          </Button>
        </div>
        <div className="event-list">
          {[
            [
              "10:30",
              "Comité de pilotage SAIE",
              "Salle Kivu",
              "Réunion",
              "success",
            ],
            ["13:00", "Réservée", "Salle Ogooué", "Confidentielle", "warning"],
            [
              "15:30",
              "Revue du portefeuille de projets",
              "Salle Congo",
              "Réunion",
              "blue",
            ],
            [
              "17:00",
              "Cérémonie institutionnelle",
              "Hall principal",
              "Événement",
              "neutral",
            ],
          ].map((event) => (
            <button key={`${event[0]}-${event[1]}`}>
              <time>{event[0]}</time>
              <span>
                <strong>{event[1]}</strong>
                <small>{event[2]}</small>
              </span>
              <Status tone={event[4]}>{event[3]}</Status>
              <Icon name="chevron" size={15} />
            </button>
          ))}
        </div>
      </section>
      <section className="panel agenda-detail">
        <div className="panel-title">
          <div>
            <h2>Ordre du jour</h2>
            <p>Comité de pilotage SAIE · RES-2026-0181</p>
          </div>
          <Status tone="success">Confirmée</Status>
        </div>
        <ol>
          <li>
            <span>01</span>
            <div>
              <strong>Validation du périmètre pilote</strong>
              <small>10:30–10:45 · Secrétariat général</small>
            </div>
          </li>
          <li>
            <span>02</span>
            <div>
              <strong>Revue des écrans et emplacements</strong>
              <small>10:45–11:15 · DSI</small>
            </div>
          </li>
          <li>
            <span>03</span>
            <div>
              <strong>Circuit éditorial et habilitations</strong>
              <small>11:15–11:45 · Communication</small>
            </div>
          </li>
          <li>
            <span>04</span>
            <div>
              <strong>Décisions et prochaines étapes</strong>
              <small>11:45–12:00 · Présidence de séance</small>
            </div>
          </li>
        </ol>
        <div className="agenda-documents">
          <Icon name="folder" />
          <span>
            <strong>3 documents associés</strong>
            <small>
              Consultables uniquement par les participants habilités
            </small>
          </span>
          <Icon name="chevron" />
        </div>
      </section>
    </div>
  )
}

function RoomApprovals({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <section className="panel table-panel">
      <Toolbar placeholder="Rechercher une demande de réservation…" />
      <div className="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Demande</th>
              <th>Salle</th>
              <th>Créneau</th>
              <th>Organisateur</th>
              <th>Échéance</th>
              <th>État</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <strong>Atelier de coordination régionale</strong>
                <small>RES-2026-0184 · 18 participants</small>
              </td>
              <td>Salle Congo</td>
              <td>16 nov. · 13:00–15:00</td>
              <td>Direction Intégration</td>
              <td>Dans 18 h</td>
              <td>
                <Status tone="warning">À valider</Status>
              </td>
              <td>
                <Button variant="secondary" onClick={() => openFlow("room")}>
                  Examiner
                </Button>
              </td>
            </tr>
            <tr>
              <td>
                <strong>Formation outils collaboratifs</strong>
                <small>RES-2026-0186 · 12 participants</small>
              </td>
              <td>Salle Kivu</td>
              <td>18 nov. · 09:00–12:00</td>
              <td>Ressources humaines</td>
              <td>Escaladée à 09:00</td>
              <td>
                <Status tone="danger">Escaladée</Status>
              </td>
              <td>
                <Button variant="secondary" onClick={() => openFlow("room")}>
                  Examiner
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  )
}

function Protocol({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <>
      <PageHeading
        page="protocol"
        action={
          <Button icon="plus" onClick={() => openFlow("visit")}>
            Préparer une visite
          </Button>
        }
      />
      <div className="protocol-hero">
        <div>
          <Status tone="blue">Prochaine arrivée · 10:30</Status>
          <h2>Délégation de la CEMAC</h2>
          <p>Réunion bilatérale · Salle CEEAC · 18 participants</p>
          <div className="protocol-meta">
            <span>
              <Icon name="calendar" />
              13 novembre, 10:30–12:30
            </span>
            <span>
              <Icon name="monitor" />
              Hall principal + palier RDC
            </span>
          </div>
          <Button variant="light" icon="eye">
            Aperçu du message
          </Button>
        </div>
        <div className="welcome-mini">
          <img src="/ceeac-logo.jpg" />
          <small>BIENVENUE À LA COMMISSION</small>
          <strong>Délégation de la CEMAC</strong>
          <span>Salle CEEAC · Rez-de-chaussée</span>
        </div>
      </div>
      <div className="two-columns">
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Visites à venir</h2>
              <p>Liées à des réservations confirmées</p>
            </div>
            <button className="link">Calendrier</button>
          </div>
          {[
            [
              "16 nov.",
              "Mission de la Banque africaine de développement",
              "Salle Congo",
              "Message à valider",
            ],
            [
              "19 nov.",
              "Délégation ministérielle — Gabon",
              "Salle CEEAC",
              "Planifié",
            ],
            [
              "24 nov.",
              "Atelier régional de coordination",
              "Salle Kivu",
              "À préparer",
            ],
          ].map((r, i) => (
            <div className="visit-row" key={r[1]}>
              <time>{r[0]}</time>
              <span>
                <strong>{r[1]}</strong>
                <small>{r[2]}</small>
              </span>
              <Status tone={i === 0 ? "warning" : i === 1 ? "blue" : "neutral"}>
                {r[3]}
              </Status>
            </div>
          ))}
        </section>
        <section className="panel orientation-card">
          <div className="panel-title">
            <div>
              <h2>Orientation visiteurs</h2>
              <p>Informations issues des référentiels</p>
            </div>
            <Status tone="success">À jour</Status>
          </div>
          <div className="wayfinding">
            <div>
              <b>RDC</b>
              <span>Accueil · Salle CEEAC</span>
            </div>
            <div>
              <b>1</b>
              <span>Cabinet · Salle Kivu</span>
            </div>
            <div>
              <b>3</b>
              <span>Département Intégration · Salle Ogooué</span>
            </div>
            <div>
              <b>6</b>
              <span>Secrétariat général · Salle Congo</span>
            </div>
          </div>
        </section>
      </div>
    </>
  )
}

function Alerts({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <>
      <PageHeading
        page="alerts"
        action={
          <Button icon="alert" onClick={() => openFlow("alert")}>
            Déclencher une alerte
          </Button>
        }
      />
      <div className="safety-note">
        <Icon name="shield" />
        <div>
          <strong>
            Les alertes SAIE complètent les dispositifs de sécurité.
          </strong>
          <span>
            Elles ne remplacent jamais les alarmes réglementaires ni les
            consignes des services compétents.
          </span>
        </div>
      </div>
      <div className="alert-template-grid">
        {[
          [
            "Évacuation",
            "Quitter calmement les locaux par les issues indiquées.",
            "danger",
          ],
          [
            "Incendie",
            "Ne pas utiliser les ascenseurs et suivre les consignes.",
            "danger",
          ],
          [
            "Incident technique",
            "Éviter la zone concernée jusqu’à nouvel ordre.",
            "warning",
          ],
          [
            "Message prioritaire",
            "Information urgente à personnaliser avant diffusion.",
            "blue",
          ],
        ].map((template) => (
          <button key={template[0]} onClick={() => openFlow("alert")}>
            <span className={template[2]}>
              <Icon name="alert" />
            </span>
            <strong>{template[0]}</strong>
            <small>{template[1]}</small>
            <Icon name="arrow" size={15} />
          </button>
        ))}
      </div>
      <section className="panel">
        <div className="panel-title">
          <div>
            <h2>Aucune alerte active</h2>
            <p>
              Les programmes ordinaires sont diffusés normalement sur 12
              lecteurs.
            </p>
          </div>
          <Status tone="success">Situation normale</Status>
        </div>
        <div className="alert-history">
          <h3>Historique récent</h3>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Message</th>
                  <th>Niveau</th>
                  <th>Portée</th>
                  <th>Déclenchée par</th>
                  <th>Durée</th>
                  <th>Réception</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <strong>Exercice technique — fin de l’alerte test</strong>
                    <small>ALT-2026-009 · levée manuellement</small>
                  </td>
                  <td>
                    <Status tone="warning">Exercice</Status>
                  </td>
                  <td>Tous les étages</td>
                  <td>Alain M. · pouvoir urgence</td>
                  <td>8 min</td>
                  <td>
                    <b>13 / 14</b>
                    <small>1 lecteur hors ligne</small>
                  </td>
                </tr>
                <tr>
                  <td>
                    <strong>Accès Est temporairement fermé</strong>
                    <small>ALT-2026-008 · expiration automatique</small>
                  </td>
                  <td>
                    <Status tone="blue">Information</Status>
                  </td>
                  <td>RDC</td>
                  <td>Alain M.</td>
                  <td>30 min</td>
                  <td>
                    <b>2 / 2</b>
                    <small>activations déclarées</small>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </>
  )
}

function Tasks({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <>
      <PageHeading page="tasks" />
      <div className="task-filter-cards">
        <button className="active">
          <strong>7</strong>
          <span>À traiter</span>
        </button>
        <button>
          <strong>2</strong>
          <span>En retard</span>
        </button>
        <button>
          <strong>1</strong>
          <span>Escaladée</span>
        </button>
        <button>
          <strong>14</strong>
          <span>Traitées ce mois</span>
        </button>
      </div>
      <section className="panel table-panel">
        <Toolbar placeholder="Rechercher une tâche…" />
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Objet</th>
                <th>Action attendue</th>
                <th>Émetteur</th>
                <th>Échéance</th>
                <th>État</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {[
                [
                  "Communiqué du Secrétariat général",
                  "Valider la version française",
                  "Nadia K.",
                  "Aujourd’hui, 15:00",
                  "À traiter",
                  "warning",
                ],
                [
                  "Réservation Salle Kivu — 16 nov.",
                  "Approuver la réservation",
                  "Loïc B.",
                  "Dans 18 h",
                  "À traiter",
                  "blue",
                ],
                [
                  "Consignes d’évacuation v3",
                  "Examiner les corrections",
                  "Service sécurité",
                  "Hier, 17:00",
                  "En retard",
                  "danger",
                ],
                [
                  "Note RH — horaires de fin d’année",
                  "Validation suppléant requise",
                  "Direction RH",
                  "Escaladée à 09:00",
                  "Escaladée",
                  "danger",
                ],
              ].map((r, i) => (
                <tr key={r[0]}>
                  <td>
                    <div className="title-cell">
                      <span className="doc-thumb">
                        <Icon name={i === 1 ? "door" : "file"} />
                      </span>
                      <span>
                        <strong>{r[0]}</strong>
                        <small>
                          {i === 1 ? "Réservation" : "Contenu"} · FR
                        </small>
                      </span>
                    </div>
                  </td>
                  <td>{r[1]}</td>
                  <td>{r[2]}</td>
                  <td>
                    <span className={r[4] === "En retard" ? "red-text" : ""}>
                      {r[3]}
                    </span>
                  </td>
                  <td>
                    <Status tone={r[5]}>{r[4]}</Status>
                  </td>
                  <td>
                    <Button
                      variant="secondary"
                      onClick={() => openFlow("validation")}
                    >
                      Ouvrir
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination count="1–4 sur 7" />
      </section>
    </>
  )
}

function Reports({ openFlow }: { openFlow: (type: string) => void }) {
  return (
    <>
      <PageHeading
        page="reports"
        action={
          <Button icon="file" onClick={() => openFlow("report")}>
            Créer un rapport
          </Button>
        }
      />
      <div className="report-filters">
        <label>
          Période
          <select>
            <option>30 derniers jours</option>
          </select>
        </label>
        <label>
          Site
          <select>
            <option>Libreville — Siège</option>
          </select>
        </label>
        <label>
          Emplacement
          <select>
            <option>Tous les niveaux</option>
          </select>
        </label>
        <Button variant="secondary">Appliquer</Button>
      </div>
      <div className="stat-grid compact">
        <div className="metric-card">
          <span>Disponibilité lecteurs</span>
          <strong>96,8%</strong>
          <small className="green-text">+1,2 pt sur la période</small>
        </div>
        <div className="metric-card">
          <span>Activations déclarées</span>
          <strong>1 284</strong>
          <small>Sur 1 326 programmées</small>
        </div>
        <div className="metric-card">
          <span>Taux d’occupation réservé</span>
          <strong>48,2%</strong>
          <small>Présence non mesurée</small>
        </div>
        <div className="metric-card">
          <span>Incidents</span>
          <strong>9</strong>
          <small>7 résolus · 2 ouverts</small>
        </div>
      </div>
      <div className="two-columns report-grid">
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Activité de diffusion</h2>
              <p>Programmée vs activée par les lecteurs</p>
            </div>
            <button className="link">Détails</button>
          </div>
          <div className="bar-chart">
            {[62, 81, 74, 90, 69, 94, 83, 88, 76, 91, 86, 96].map((v, i) => (
              <div key={i}>
                <i style={{ height: `${v}%` }} />
                <span>{i + 1}</span>
              </div>
            ))}
          </div>
          <div className="chart-legend">
            <span>
              <i />
              Activation déclarée
            </span>
            <b>Une activation n’est pas une preuve de vue humaine.</b>
          </div>
        </section>
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Disponibilité par niveau</h2>
              <p>Contact des lecteurs</p>
            </div>
          </div>
          <div className="availability-list">
            {[
              "RDC",
              "1er étage",
              "2e étage",
              "3e étage",
              "4e étage",
              "5e étage",
              "6e étage",
            ].map((x, i) => (
              <div key={x}>
                <span>{x}</span>
                <i>
                  <b style={{ width: i === 2 ? "82%" : "99%" }} />
                </i>
                <strong>{i === 2 ? "82,4" : "99,1"}%</strong>
              </div>
            ))}
          </div>
        </section>
      </div>
    </>
  )
}

function Admin() {
  const [tab, setTab] = useState("Utilisateurs")
  return (
    <>
      <PageHeading
        page="admin"
        action={<Button icon="plus">Nouvel utilisateur</Button>}
      />
      <div className="tabs">
        {[
          "Utilisateurs",
          "Rôles & délégations",
          "Référentiels",
          "Paramètres",
          "Journal d’audit",
        ].map((x) => (
          <button
            className={tab === x ? "active" : ""}
            onClick={() => setTab(x)}
            key={x}
          >
            {x}
          </button>
        ))}
      </div>
      {tab === "Utilisateurs" ? (
        <section className="panel table-panel">
          <Toolbar placeholder="Rechercher un utilisateur ou une structure…" />
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Utilisateur</th>
                  <th>Structure</th>
                  <th>Rôle</th>
                  <th>Pouvoir étendu</th>
                  <th>Statut</th>
                  <th>Dernière activité</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {[
                  [
                    "Nadia K.",
                    "Communication",
                    "Contributeur · Validateur",
                    "—",
                    "Actif",
                    "Il y a 4 min",
                  ],
                  [
                    "Alain M.",
                    "DSI",
                    "Administrateur technique · Diffuseur",
                    "Urgence · expire 31 déc.",
                    "Actif",
                    "Il y a 12 min",
                  ],
                  [
                    "Mireille T.",
                    "Moyens généraux",
                    "Gestionnaire de salle",
                    "Prestations",
                    "Actif",
                    "Il y a 1 h",
                  ],
                  [
                    "Sylvain E.",
                    "Audit interne",
                    "Auditeur",
                    "—",
                    "Suspendu",
                    "04 nov. 2026",
                  ],
                ].map((r, i) => (
                  <tr key={r[0]}>
                    <td>
                      <div className="user-cell">
                        <span>
                          {r[0]
                            .split(" ")
                            .map((x) => x[0])
                            .join("")}
                        </span>
                        <strong>
                          {r[0]}
                          <small>profil fictif {i + 1}</small>
                        </strong>
                      </div>
                    </td>
                    <td>{r[1]}</td>
                    <td>{r[2]}</td>
                    <td>{r[3]}</td>
                    <td>
                      <Status tone={r[4] === "Actif" ? "success" : "neutral"}>
                        {r[4]}
                      </Status>
                    </td>
                    <td>{r[5]}</td>
                    <td>
                      <button className="icon-button">
                        <Icon name="more" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination count="1–4 sur 9" />
        </section>
      ) : (
        <section className="panel placeholder-admin">
          <div className="empty-icon">
            <Icon
              name={tab.includes("Journal") ? "shield" : "settings"}
              size={26}
            />
          </div>
          <h2>{tab}</h2>
          <p>
            Cette vue applique les périmètres, la séparation des rôles et la
            journalisation définis par le cahier v3.0.
          </p>
          <div className="admin-preview">
            {tab === "Rôles & délégations"
              ? [
                  "Permission",
                  "Rôle",
                  "Structure",
                  "Site / zone",
                  "Début",
                  "Fin",
                  "Suppléant",
                ].map((x) => <span key={x}>{x}</span>)
              : [
                  "Identité",
                  "Diffusion",
                  "Fonctionnement",
                  "Salles",
                  "Conservation",
                  "Intégrations",
                ].map((x) => <span key={x}>{x}</span>)}
          </div>
        </section>
      )}
    </>
  )
}

function Player() {
  const [mode, setMode] = useState("Paysage")
  const [state, setState] = useState("Normal")
  return (
    <>
      <PageHeading page="player" />
      <div className="player-controls">
        <div className="segmented">
          {["Paysage", "Portrait", "Panneau de salle"].map((x) => (
            <button
              className={mode === x ? "active" : ""}
              onClick={() => setMode(x)}
              key={x}
            >
              {x}
            </button>
          ))}
        </div>
        <label>
          État
          <select value={state} onChange={(e) => setState(e.target.value)}>
            <option>Normal</option>
            <option>Alerte</option>
            <option>Hors ligne</option>
            <option>Sans programme</option>
          </select>
        </label>
        <Button variant="secondary" icon="eye">
          Plein écran
        </Button>
      </div>
      <div
        className={`display-stage ${
          mode === "Portrait" ? "portrait-stage" : ""
        }`}
      >
        {mode === "Panneau de salle" ? (
          <RoomDisplay state={state} />
        ) : state === "Alerte" ? (
          <AlertDisplay />
        ) : state === "Sans programme" ? (
          <FallbackDisplay />
        ) : (
          <InstitutionalDisplay
            offline={state === "Hors ligne"}
            portrait={mode === "Portrait"}
          />
        )}
      </div>
      <div className="display-specs">
        <span>
          <b>Format</b>
          {mode === "Portrait"
            ? "1080 × 1920"
            : mode === "Panneau de salle"
              ? "1280 × 800"
              : "1920 × 1080"}
        </span>
        <span>
          <b>Marge sûre</b>5 %
        </span>
        <span>
          <b>Audio</b>Muet par défaut
        </span>
        <span>
          <b>Langues</b>FR / EN en rotation
        </span>
      </div>
    </>
  )
}

function InstitutionalDisplay({
  offline,
  portrait,
}: {
  offline: boolean
  portrait: boolean
}) {
  return (
    <div className={`institutional-display ${portrait ? "portrait" : ""}`}>
      <header>
        <img src="/ceeac-logo.jpg" />
        <div>
          <b>
            COMMUNAUTÉ ÉCONOMIQUE DES ÉTATS
            <br />
            DE L’AFRIQUE CENTRALE
          </b>
          <small>Commission de la CEEAC</small>
        </div>
        <time>
          10:42<small>Vendredi 13 novembre 2026</small>
        </time>
      </header>
      {offline && (
        <div className="offline-ribbon">
          <Icon name="wifi" /> Mode hors connexion · dernière mise à jour 10:38
        </div>
      )}
      <main>
        <div className="display-copy">
          <span>COMMUNIQUÉ INSTITUTIONNEL</span>
          <h2>Ensemble pour une Afrique centrale intégrée</h2>
          <p>
            La Commission réaffirme son engagement en faveur d’une coopération
            régionale durable et solidaire.
          </p>
          <div className="display-rule" />
          <small>
            Retrouvez les informations institutionnelles autorisées sur les
            écrans de votre étage.
          </small>
        </div>
        <div className="africa-art">
          <div className="circle-art">
            <Icon name="building" size={72} />
          </div>
          <span>
            11 États membres
            <br />
            Une vision commune
          </span>
        </div>
      </main>
      <footer>
        <div>
          <b>Réunions du jour</b>
          <span>10:30 · Comité de pilotage — Salle Kivu</span>
          <span>13:00 · Réservée — Salle Ogooué</span>
          <span className="display-weather">
            <Icon name="sun" size={13} /> Libreville · 28°
          </span>
        </div>
        <strong>FR</strong>
      </footer>
    </div>
  )
}

function AlertDisplay() {
  return (
    <div className="alert-display">
      <div className="alert-mark">
        <Icon name="alert" size={64} />
      </div>
      <small>ALERTE PRIORITAIRE</small>
      <h2>Veuillez éviter l’accès Est du bâtiment</h2>
      <p>
        Utilisez l’entrée principale et suivez les indications du personnel
        habilité.
      </p>
      <div className="alert-time">
        Message activé à 10:42 · Fin prévue à 11:12
      </div>
      <footer>
        Cette information complète les dispositifs réglementaires en vigueur.
      </footer>
    </div>
  )
}

function FallbackDisplay() {
  return (
    <div className="fallback-display">
      <img src="/ceeac-logo.jpg" />
      <h2>Commission de la CEEAC</h2>
      <p>Aucun programme n’est disponible pour cette plage horaire.</p>
      <small>Contenu institutionnel de repli · public et validé</small>
    </div>
  )
}

function RoomDisplay({ state }: { state: string }) {
  return (
    <div className="room-display">
      <header>
        <img src="/ceeac-logo.jpg" />
        <span>SAIE–CEEAC</span>
        <time>10:42</time>
      </header>
      <main>
        <div>
          <small>SALLE DE RÉUNION · 1ER ÉTAGE</small>
          <h2>Salle Kivu</h2>
          <span className="room-state">LIBRE</span>
          <p>Disponible jusqu’à 13:00</p>
        </div>
        <div className="next-meeting">
          <small>PROCHAINE RÉSERVATION</small>
          <b>13:00 — 14:30</b>
          <p>Comité de coordination régionale</p>
          <span>12 participants · Visioconférence</span>
        </div>
      </main>
      {state === "Hors ligne" && (
        <footer>
          <Icon name="wifi" /> Statut non actualisé · dernier contact 10:38
        </footer>
      )}
    </div>
  )
}

function Assumptions() {
  const functionalAudit = [
    [
      "01",
      "Communication institutionnelle",
      "Messages de direction, communiqués et annonces officielles",
      "contents",
    ],
    [
      "02",
      "Gestion des réunions",
      "Calendrier, salles, ordres du jour et événements",
      "rooms",
    ],
    [
      "03",
      "Accueil & protocole",
      "Délégations, bienvenue et orientation",
      "protocol",
    ],
    [
      "04",
      "Ressources humaines",
      "Notes de service, formations et informations au personnel",
      "contents",
    ],
    [
      "05",
      "Tableaux de bord",
      "Indicateurs, statistiques et suivi de projets",
      "dashboard",
    ],
    [
      "06",
      "Informations pratiques",
      "Heure, météo, annonces et consignes",
      "dashboard",
    ],
    [
      "07",
      "Alertes urgentes",
      "Évacuation, incendie, incidents et messages prioritaires",
      "alerts",
    ],
    [
      "08",
      "Gestion multimédia",
      "Images, vidéos, PDF, animations et bandeaux",
      "media",
    ],
    [
      "09",
      "Programmation",
      "Publication datée, récurrente et automatique",
      "schedule",
    ],
    [
      "10",
      "Administration",
      "Écrans, contenus, rôles et autorisations",
      "admin",
    ],
  ]
  const coverage = [
    [
      "Identité, sécurité & habilitations",
      "V01–V06, V34–V35, V38, V42",
      "P08 · PDF09",
      "P0",
    ],
    ["Documents & médias", "V11–V14, V41", "P02 · PDF10", "P0"],
    ["Publication & composition", "V15–V24", "P01, P05 · PDF04–05", "P0"],
    [
      "Écrans & continuité",
      "V07–V10, V40 · T01–T04",
      "P06 · PDF06, PDF08",
      "P0",
    ],
    [
      "Salles & réunions",
      "V25–V32 · T03",
      "P03, P04, P07 · PDF01–03, 07",
      "P0",
    ],
    ["Accueil & orientation", "V19, V28 · T05", "P09", "P1"],
    ["Rapports & administration", "V33–V41", "P08 · PDF05–09", "P0"],
  ]
  return (
    <>
      <PageHeading page="assumptions" />
      <div className="source-banner">
        <Icon name="check" />
        <div>
          <strong>
            Source prioritaire : cahier des charges SAIE-CEEAC v3.0
          </strong>
          <span>
            Version consolidée du 3 novembre 2026. Le document DOCX joint est
            une note de cadrage antérieure et moins détaillée.
          </span>
        </div>
        <Status tone="success">Analysé</Status>
      </div>
      <section className="panel functional-audit">
        <div className="panel-title">
          <div>
            <h2>Audit fonctionnel des 10 modules demandés</h2>
            <p>
              Chaque module est matérialisé par une vue, des données cohérentes
              et au moins une interaction identifiable.
            </p>
          </div>
          <Status tone="success">10 / 10 couverts</Status>
        </div>
        <div className="audit-grid">
          {functionalAudit.map((item) => (
            <div key={item[0]}>
              <span>{item[0]}</span>
              <p>
                <strong>{item[1]}</strong>
                <small>{item[2]}</small>
              </p>
              <Status tone="success">Couvert</Status>
            </div>
          ))}
        </div>
      </section>
      <div className="two-columns assumptions-grid">
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Points à valider</h2>
              <p>Décisions non contractuelles ou dépendantes du pilote</p>
            </div>
            <span className="count-badge">7</span>
          </div>
          <div className="decision-list">
            {[
              [
                "Pouvoir « prestations »",
                "Confirmer son rattachement au gestionnaire de salle.",
                "Q1",
              ],
              [
                "Délais et seuils",
                "Valider 2 jours pour un contenu, 24 h pour une réservation et 30 min pour une alerte.",
                "HAB-04",
              ],
              [
                "Parc réel",
                "L’hypothèse de 14 écrans est fictive ; un inventaire sur site reste requis.",
                "H5",
              ],
              [
                "Pilotage des dalles",
                "Confirmer HDMI-CEC, RS-232 ou API après choix du matériel.",
                "PLY-08",
              ],
              [
                "Conservation hors ligne",
                "Arbitrer 24 h pour le public et 1 h pour l’interne.",
                "PLY-05",
              ],
            ].map((r) => (
              <div key={r[0]}>
                <span>{r[2]}</span>
                <p>
                  <strong>{r[0]}</strong>
                  {r[1]}
                </p>
              </div>
            ))}
          </div>
        </section>
        <section className="panel">
          <div className="panel-title">
            <div>
              <h2>Propositions complémentaires</h2>
              <p>Éléments UX ajoutés, non prescrits comme règles métier</p>
            </div>
          </div>
          <div className="proposal-list">
            <div>
              <Icon name="search" />
              <span>
                <strong>Recherche globale contextuelle</strong>
                <p>
                  Palette de commandes pour accélérer l’accès aux objets
                  autorisés.
                </p>
              </span>
            </div>
            <div>
              <Icon name="layout" />
              <span>
                <strong>Mode aperçu côte à côte</strong>
                <p>Comparaison FR / EN avant soumission à validation.</p>
              </span>
            </div>
            <div>
              <Icon name="chart" />
              <span>
                <strong>Vue synoptique schématique</strong>
                <p>
                  Représentation des sept niveaux sans inventer de plan
                  architectural.
                </p>
              </span>
            </div>
            <div>
              <Icon name="info" />
              <span>
                <strong>Centre de cadrage intégré</strong>
                <p>
                  Matrice de couverture et arbitrages accessibles dans le
                  prototype.
                </p>
              </span>
            </div>
          </div>
        </section>
      </div>
      <section className="panel coverage">
        <div className="panel-title">
          <div>
            <h2>Matrice de couverture</h2>
            <p>Correspondance entre domaines, vues, parcours et documents</p>
          </div>
          <Button variant="secondary" icon="download">
            Exporter la matrice
          </Button>
        </div>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Domaine</th>
                <th>Vues couvertes</th>
                <th>Parcours & PDF</th>
                <th>Priorité</th>
                <th>État maquette</th>
              </tr>
            </thead>
            <tbody>
              {coverage.map((r) => (
                <tr key={r[0]}>
                  <td>
                    <strong>{r[0]}</strong>
                  </td>
                  <td>{r[1]}</td>
                  <td>{r[2]}</td>
                  <td>
                    <span className="priority">{r[3]}</span>
                  </td>
                  <td>
                    <Status tone="success">Couvert</Status>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}

function PageHeading({
  page,
  action,
}: {
  page: Page
  action?: React.ReactNode
}) {
  return (
    <>
      <div className="page-heading">
        <div>
          <h1>{pageTitles[page][0]}</h1>
          <p>{pageTitles[page][1]}</p>
        </div>
        {action && <div className="heading-actions">{action}</div>}
      </div>
      <DemoNote />
    </>
  )
}

function Pagination({ count }: { count: string }) {
  return (
    <div className="pagination">
      <span>{count}</span>
      <div>
        <button disabled>Précédent</button>
        <button className="active">1</button>
        <button>2</button>
        <button>Suivant</button>
      </div>
    </div>
  )
}

const flowConfig: Record<string, { title: string steps: string[] }> = {
  content: {
    title: "Créer un communiqué",
    steps: [
      "Informations",
      "Composition",
      "Langues",
      "Vérification",
      "Validation",
      "Programmation",
      "Aperçu écran",
    ],
  },
  validation: {
    title: "Examiner la demande",
    steps: ["Détail", "Décision", "Confirmation"],
  },
  pdf: {
    title: "Importer un document PDF",
    steps: ["Import", "Analyse", "Pages", "Liste de lecture"],
  },
  device: {
    title: "Enrôler un lecteur",
    steps: ["Code", "Association", "Affectation", "Synchronisation"],
  },
  room: {
    title: "Réserver une salle",
    steps: ["Besoin", "Créneau", "Conflit", "Alternative", "Confirmation"],
  },
  visit: {
    title: "Préparer une visite",
    steps: ["Délégation", "Réservation", "Message", "Écrans", "Validation"],
  },
  alert: {
    title: "Déclencher une alerte",
    steps: ["Message", "Portée", "Aperçu", "Confirmation", "Suivi"],
  },
  report: {
    title: "Générer un rapport",
    steps: ["Périmètre", "Indicateurs", "Aperçu PDF", "Génération"],
  },
}

function FlowModal({ type, onClose }: { type: string onClose: () => void }) {
  const cfg = flowConfig[type]
  const [step, setStep] = useState(0)
  const [choice, setChoice] = useState("correction")
  const last = step === cfg.steps.length - 1
  const next = () => (last ? onClose() : setStep((s) => s + 1))
  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true">
      <div className="flow-modal">
        <header>
          <div>
            <small>PARCOURS INTERACTIF</small>
            <h2>{cfg.title}</h2>
          </div>
          <button className="close" onClick={onClose}>
            <Icon name="x" />
          </button>
        </header>
        <div className="flow-progress">
          {cfg.steps.map((s, i) => (
            <div
              className={i < step ? "done" : i === step ? "active" : ""}
              key={s}
            >
              <span>{i < step ? <Icon name="check" size={13} /> : i + 1}</span>
              <b>{s}</b>
            </div>
          ))}
        </div>
        <div className="flow-content">
          <div className="step-label">
            ÉTAPE {step + 1} SUR {cfg.steps.length}
          </div>
          {type === "content" && <ContentStep step={step} />}
          {type === "validation" && (
            <ValidationStep step={step} choice={choice} setChoice={setChoice} />
          )}
          {type === "pdf" && <PdfStep step={step} />}
          {type === "device" && <DeviceStep step={step} />}
          {type === "room" && <RoomStep step={step} />}
          {type === "visit" && <VisitStep step={step} />}
          {type === "alert" && <AlertStep step={step} />}
          {type === "report" && <ReportStep step={step} />}
        </div>
        <footer>
          <Button
            variant="secondary"
            onClick={step ? () => setStep((s) => s - 1) : onClose}
          >
            {step ? "Retour" : "Annuler"}
          </Button>
          <div className="footer-note">
            <Icon name="shield" size={14} /> Action tracée dans le journal
          </div>
          <Button onClick={next}>
            {last
              ? "Terminer"
              : step === cfg.steps.length - 2
                ? "Confirmer et continuer"
                : "Continuer"}
            <Icon name="arrow" size={15} />
          </Button>
        </footer>
      </div>
    </div>
  )
}

function ContentStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Informations générales</h3>
        <p>
          Définissez le propriétaire et la classification avant la composition.
        </p>
        <div className="form-grid">
          <label className="wide">
            Titre du contenu <em>*</em>
            <input defaultValue="Communiqué du Secrétariat général" />
          </label>
          <label>
            Catégorie <em>*</em>
            <select>
              <option>Communiqué</option>
            </select>
          </label>
          <label>
            Propriétaire <em>*</em>
            <select>
              <option>Secrétariat général</option>
            </select>
          </label>
          <label>
            Classification <em>*</em>
            <select>
              <option>Interne</option>
              <option>Public</option>
            </select>
            <small>Les contenus restreints sont exclus des écrans.</small>
          </label>
          <label>
            Priorité
            <select>
              <option>50 — Normale</option>
            </select>
          </label>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Composition du message</h3>
        <p>Le modèle est compatible avec les écrans internes ciblés.</p>
        <div className="composition-choice">
          <div className="selected">
            <Icon name="layout" />
            <span>
              <strong>Communication interne — paysage</strong>
              <small>16:9 · texte, visuel, bandeau, horloge</small>
            </span>
            <Icon name="check" />
          </div>
          <button>
            <Icon name="image" />
            Choisir un média
          </button>
        </div>
        <label className="field">
          Texte principal <em>*</em>
          <textarea defaultValue="La Commission informe l’ensemble du personnel de la tenue de la réunion de coordination interservices le lundi 16 novembre à 10 heures." />
        </label>
        <div className="counter">147 / 420 caractères</div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Versions linguistiques</h3>
        <p>
          Chaque langue est validée séparément. Une langue non approuvée sera
          ignorée.
        </p>
        <div className="language-tabs">
          <button className="active">
            FR <Status tone="success">Complet</Status>
          </button>
          <button>
            EN <Status tone="warning">À relire</Status>
          </button>
          <button>
            PT <span>Inactif</span>
          </button>
          <button>
            ES <span>Inactif</span>
          </button>
        </div>
        <div className="success-box">
          <Icon name="check" />
          <span>
            <strong>La version française tient dans le modèle.</strong>
            <small>Aucun débordement détecté à 1920 × 1080.</small>
          </span>
        </div>
      </>
    )
  if (step === 3)
    return (
      <>
        <h3>Vérification avant soumission</h3>
        <p>Les contrôles bloquants ont été exécutés sur les deux langues.</p>
        <div className="check-list">
          <span>
            <Icon name="check" />
            Métadonnées complètes
          </span>
          <span>
            <Icon name="check" />
            Classification compatible avec les cibles
          </span>
          <span>
            <Icon name="check" />
            Média sain et prêt
          </span>
          <span>
            <Icon name="check" />
            Rendu FR sans débordement
          </span>
          <span className="warn">
            <Icon name="alert" />
            Version EN : relecture recommandée
          </span>
        </div>
      </>
    )
  if (step === 4)
    return (
      <>
        <h3>Soumission et décision</h3>
        <p>La version française a été soumise à un validateur distinct.</p>
        <div className="workflow-card">
          <div className="avatar">NK</div>
          <div>
            <strong>
              Nadia K. <small>Contributrice</small>
            </strong>
            <p>Soumis le 13 novembre à 10:44</p>
          </div>
          <Icon name="arrow" />
          <div className="avatar dark">JM</div>
          <div>
            <strong>
              Jean M. <small>Validateur FR</small>
            </strong>
            <p>Décision attendue avant le 17 novembre</p>
          </div>
        </div>
        <div className="success-box">
          <Icon name="check" />
          <span>
            <strong>Version française approuvée</strong>
            <small>
              La version est désormais immuable. Toute modification créera une
              révision.
            </small>
          </span>
        </div>
      </>
    )
  if (step === 5)
    return (
      <>
        <h3>Programmer la diffusion</h3>
        <p>La campagne référence la version approuvée v2-FR.</p>
        <div className="form-grid">
          <label>
            Début <em>*</em>
            <input defaultValue="16/11/2026 08:00" />
          </label>
          <label>
            Fin <em>*</em>
            <input defaultValue="20/11/2026 18:00" />
          </label>
          <label className="wide">
            Cibles <em>*</em>
            <div className="target-box">
              <span>
                Groupe · Écrans internes 1er–6e{" "}
                <button>
                  <Icon name="x" size={13} />
                </button>
              </span>
            </div>
          </label>
        </div>
        <div className="warning-box">
          <Icon name="alert" />
          <span>
            <strong>1 conflit détecté sur 2 écrans</strong>
            <small>
              La campagne « Information RH » a la même priorité à 12:00. La
              rotation ordonnée sera appliquée.
            </small>
          </span>
        </div>
      </>
    )
  return (
    <>
      <h3>Aperçu sur le lecteur</h3>
      <p>Simulation au 16 novembre à 10:00 · ECR-3E-01 · Africa/Libreville.</p>
      <div className="modal-screen-preview">
        <header>
          <img src="/ceeac-logo.jpg" />
          <b>Commission de la CEEAC</b>
          <time>10:00</time>
        </header>
        <main>
          <small>COMMUNIQUÉ</small>
          <h4>Réunion de coordination interservices</h4>
          <p>Lundi 16 novembre à 10 heures · Salle Congo</p>
        </main>
        <footer>Communication interne · FR</footer>
      </div>
      <div className="proof-note">
        <Icon name="info" />
        <span>
          Cet aperçu simule le rendu. Il ne constitue pas une preuve de
          diffusion effective.
        </span>
      </div>
    </>
  )
}

function ValidationStep({
  step,
  choice,
  setChoice,
}: {
  step: number
  choice: string
  setChoice: (x: string) => void
}) {
  if (step === 0)
    return (
      <>
        <h3>Communiqué du Secrétariat général</h3>
        <p>
          Version FR figée · soumise par Nadia K. · échéance aujourd’hui à 15:00
        </p>
        <div className="validation-preview">
          <span>COMMUNIQUÉ</span>
          <h4>Réunion de coordination interservices</h4>
          <p>
            La Commission informe l’ensemble du personnel de la tenue de la
            réunion le lundi 16 novembre à 10 heures.
          </p>
        </div>
        <div className="metadata-row">
          <span>
            <b>Classification</b>Interne
          </span>
          <span>
            <b>Cibles proposées</b>Étages 1–6
          </span>
          <span>
            <b>Version</b>v2 · Français
          </span>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Votre décision</h3>
        <p>L’auteur et le validateur sont distincts conformément à HAB-02.</p>
        <div className="decision-options">
          <button
            className={choice === "approve" ? "selected" : ""}
            onClick={() => setChoice("approve")}
          >
            <Icon name="check" />
            <span>
              <strong>Approuver la version</strong>
              <small>Elle pourra être programmée par un diffuseur.</small>
            </span>
          </button>
          <button
            className={choice === "correction" ? "selected danger-choice" : ""}
            onClick={() => setChoice("correction")}
          >
            <Icon name="edit" />
            <span>
              <strong>Retourner pour correction</strong>
              <small>Un motif précis sera transmis à la contributrice.</small>
            </span>
          </button>
        </div>
        {choice === "correction" && (
          <label className="field">
            Motif du retour <em>*</em>
            <textarea defaultValue="Préciser le lieu de la réunion dans le corps du message et harmoniser l’horaire avec la réservation." />
          </label>
        )}
      </>
    )
  return (
    <>
      <h3>Confirmer le retour pour correction</h3>
      <p>
        La version FR repassera en brouillon. La version EN reste inchangée.
      </p>
      <div className="confirmation-box danger">
        <Icon name="alert" />
        <div>
          <strong>Cette décision est immédiatement notifiée à Nadia K.</strong>
          <p>
            Le motif, votre identité, votre rôle et l’heure serveur seront
            inscrits dans l’historique.
          </p>
        </div>
      </div>
    </>
  )
}

function PdfStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Importer un document</h3>
        <p>
          PDF, 50 Mo maximum. L’intégrité, le type MIME et l’antivirus seront
          contrôlés.
        </p>
        <div className="upload-zone">
          <Icon name="upload" size={28} />
          <strong>programme-session-statutaire.pdf</strong>
          <span>4,2 Mo · import terminé</span>
          <div>
            <i style={{ width: "100%" }} />
          </div>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Analyse et conversion</h3>
        <p>Aucun fichier ne peut être publié avant l’état « Prêt ».</p>
        <div className="scan-steps">
          <span className="done">
            <Icon name="check" />
            Extension et signature MIME
          </span>
          <span className="done">
            <Icon name="check" />
            Empreinte SHA-256
          </span>
          <span className="done">
            <Icon name="check" />
            Analyse antivirus
          </span>
          <span className="active">
            <Icon name="clock" />
            Génération des miniatures
          </span>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Choisir les pages à diffuser</h3>
        <p>
          Définissez une durée par page. Les pages non sélectionnées restent
          dans le document.
        </p>
        <div className="pdf-pages">
          {[1, 2, 3, 4, 5, 6].map((n) => (
            <button className={[1, 2, 5].includes(n) ? "selected" : ""} key={n}>
              <span>
                <Icon name="file" />
              </span>
              <b>Page {n}</b>
              <small>
                {[1, 2, 5].includes(n) ? "12 secondes" : "Non sélectionnée"}
              </small>
            </button>
          ))}
        </div>
      </>
    )
  return (
    <>
      <h3>Ajouter à une liste de lecture</h3>
      <p>Le document est prêt. Trois pages seront intégrées.</p>
      <div className="playlist">
        <strong>Communication institutionnelle — RDC</strong>
        <div>
          <span>1</span>
          <Icon name="image" />
          <p>
            Bienvenue à la Commission<small>10 s</small>
          </p>
          <Icon name="more" />
        </div>
        <div className="new">
          <span>2</span>
          <Icon name="file" />
          <p>
            Programme de la session · pages 1, 2, 5<small>36 s</small>
          </p>
          <Icon name="more" />
        </div>
        <div>
          <span>3</span>
          <Icon name="play" />
          <p>
            Message du Président<small>42 s</small>
          </p>
          <Icon name="more" />
        </div>
      </div>
    </>
  )
}

function DeviceStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Code d’appairage temporaire</h3>
        <p>
          Saisissez le code affiché sur le lecteur. Il expire après 10 minutes.
        </p>
        <div className="pairing-code">
          <input defaultValue="C7F" />
          <span>—</span>
          <input defaultValue="9K2" />
          <div>
            Expire dans <b>08:42</b>
          </div>
        </div>
        <div className="info-box">
          <Icon name="shield" />
          <span>
            Aucun jeton durable n’est affiché ou partagé entre les lecteurs.
          </span>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Lecteur détecté</h3>
        <p>Vérifiez l’identité technique avant de poursuivre.</p>
        <div className="device-identity">
          <span>
            <Icon name="monitor" size={30} />
          </span>
          <div>
            <strong>Lecteur SAIE non associé</strong>
            <small>ID matériel · LCT-8A4F-2026</small>
          </div>
          <Status tone="success">En ligne</Status>
        </div>
        <div className="metadata-row">
          <span>
            <b>Version</b>1.6.2 stable
          </span>
          <span>
            <b>Résolution</b>1920 × 1080
          </span>
          <span>
            <b>Stockage</b>54 Go libres
          </span>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Affecter le lecteur</h3>
        <p>L’écran physique et le lecteur restent deux objets distincts.</p>
        <div className="form-grid">
          <label>
            Site
            <select>
              <option>Libreville — Siège</option>
            </select>
          </label>
          <label>
            Niveau
            <select>
              <option>2e étage</option>
            </select>
          </label>
          <label className="wide">
            Écran associé
            <select>
              <option>ECR-2E-02 · Couloir Est</option>
            </select>
          </label>
          <label>
            Orientation
            <select>
              <option>Paysage</option>
            </select>
          </label>
          <label>
            Groupe
            <select>
              <option>Communication interne</option>
            </select>
          </label>
        </div>
      </>
    )
  return (
    <>
      <h3>Synchronisation initiale</h3>
      <p>
        Le lecteur ne basculera qu’après téléchargement complet et vérification
        du manifeste.
      </p>
      <div className="sync-status">
        <div>
          <span>Téléchargement du manifeste v413</span>
          <b>100%</b>
          <i>
            <em style={{ width: "100%" }} />
          </i>
        </div>
        <div>
          <span>Ressources · 18 sur 18</span>
          <b>100%</b>
          <i>
            <em style={{ width: "100%" }} />
          </i>
        </div>
        <div>
          <span>Activation atomique</span>
          <b>Terminée</b>
          <i>
            <em style={{ width: "100%" }} />
          </i>
        </div>
      </div>
      <div className="success-box">
        <Icon name="check" />
        <span>
          <strong>Lecteur associé et synchronisé</strong>
          <small>Premier heartbeat reçu à 10:48:12.</small>
        </span>
      </div>
    </>
  )
}

function RoomStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Quel est votre besoin ?</h3>
        <p>Les champs marqués sont nécessaires au contrôle de disponibilité.</p>
        <div className="form-grid">
          <label className="wide">
            Objet interne <em>*</em>
            <input defaultValue="Atelier de coordination régionale" />
          </label>
          <label>
            Date <em>*</em>
            <input defaultValue="16/11/2026" />
          </label>
          <label>
            Participants <em>*</em>
            <input defaultValue="18" />
          </label>
          <label>
            Début <em>*</em>
            <input defaultValue="13:00" />
          </label>
          <label>
            Fin <em>*</em>
            <input defaultValue="15:00" />
          </label>
          <label className="wide check-label">
            <input type="checkbox" /> Réunion confidentielle — le panneau
            affichera uniquement « Réservée »
          </label>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Sélectionner une salle</h3>
        <p>
          La disponibilité inclut les temps de préparation et de remise en état.
        </p>
        <div className="room-options">
          <button className="selected">
            <span>
              <Icon name="door" />
            </span>
            <div>
              <strong>Salle Ogooué</strong>
              <small>3e étage · 20 places · Visioconférence</small>
            </div>
            <Status tone="success">Disponible</Status>
          </button>
          <button>
            <span>
              <Icon name="door" />
            </span>
            <div>
              <strong>Salle Congo</strong>
              <small>6e étage · 40 places · Sonorisation</small>
            </div>
            <Status tone="success">Disponible</Status>
          </button>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Conflit détecté lors de la confirmation</h3>
        <p>
          Une autre réservation a été confirmée simultanément. Votre saisie est
          conservée.
        </p>
        <div className="confirmation-box danger">
          <Icon name="alert" />
          <div>
            <strong>Salle Ogooué indisponible de 12:45 à 15:30</strong>
            <p>
              La contrainte serveur a empêché le chevauchement. Aucune double
              réservation n’a été créée.
            </p>
          </div>
        </div>
        <div className="conflict-slot">
          <span>
            Votre demande<b>13:00 — 15:00</b>
          </span>
          <span>
            Réservation confirmée<b>12:45 — 15:30</b>
          </span>
        </div>
      </>
    )
  if (step === 3)
    return (
      <>
        <h3>Créneau alternatif</h3>
        <p>
          Deux solutions respectent la capacité et les équipements demandés.
        </p>
        <div className="room-options">
          <button className="selected">
            <span>
              <Icon name="door" />
            </span>
            <div>
              <strong>Salle Congo · même horaire</strong>
              <small>6e étage · 40 places · 13:00–15:00</small>
            </div>
            <Status tone="success">Recommandé</Status>
          </button>
          <button>
            <span>
              <Icon name="clock" />
            </span>
            <div>
              <strong>Salle Ogooué · horaire décalé</strong>
              <small>3e étage · 20 places · 16:00–18:00</small>
            </div>
            <Status tone="blue">Disponible</Status>
          </button>
        </div>
      </>
    )
  return (
    <>
      <h3>Réservation confirmée</h3>
      <p>Les agendas autorisés et le panneau de salle seront mis à jour.</p>
      <div className="booking-confirmed">
        <span>
          <Icon name="check" size={30} />
        </span>
        <h4>Atelier de coordination régionale</h4>
        <p>16 novembre 2026 · 13:00–15:00</p>
        <strong>Salle Congo · 6e étage</strong>
        <small>Référence RES-2026-0184 · Africa/Libreville</small>
      </div>
    </>
  )
}

function VisitStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Informations sur la délégation</h3>
        <p>Les données sont fictives et limitées au nécessaire.</p>
        <div className="form-grid">
          <label className="wide">
            Nom de la délégation <em>*</em>
            <input defaultValue="Délégation de la CEMAC" />
          </label>
          <label>
            Institution
            <select>
              <option>Commission de la CEMAC</option>
            </select>
          </label>
          <label>
            Nombre de visiteurs
            <input defaultValue="18" />
          </label>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Réservation associée</h3>
        <p>
          Un message d’accueil exige une réservation confirmée et non
          confidentielle.
        </p>
        <div className="booking-link">
          <Icon name="calendar" />
          <span>
            <strong>RES-2026-0172 · Réunion bilatérale</strong>
            <small>13 novembre · 10:30–12:30 · Salle CEEAC</small>
          </span>
          <Status tone="success">Confirmée</Status>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Composer le message de bienvenue</h3>
        <p>Le protocole valide les dénominations et éléments visuels.</p>
        <label className="field">
          Message principal <em>*</em>
          <textarea defaultValue="La Commission de la CEEAC souhaite la bienvenue à la délégation de la CEMAC." />
        </label>
        <div className="language-tabs">
          <button className="active">Français</button>
          <button>English</button>
        </div>
      </>
    )
  if (step === 3)
    return (
      <>
        <h3>Choisir les écrans d’accueil</h3>
        <p>
          La diffusion commencera et prendra fin automatiquement avec la visite.
        </p>
        <div className="target-list">
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Hall principal</b>RDC · Écran paysage
            </span>
          </label>
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Orientation accueil</b>RDC · Écran portrait
            </span>
          </label>
          <label>
            <input type="checkbox" />
            <span>
              <b>Palier 1er étage</b>Hors itinéraire
            </span>
          </label>
        </div>
      </>
    )
  return (
    <>
      <h3>Soumettre au circuit de validation</h3>
      <p>Le message doit être approuvé avant son activation automatique.</p>
      <div className="workflow-card">
        <div className="avatar">PT</div>
        <div>
          <strong>Équipe Protocole</strong>
          <p>Auteur · message FR / EN</p>
        </div>
        <Icon name="arrow" />
        <div className="avatar dark">CV</div>
        <div>
          <strong>Communication</strong>
          <p>Validation institutionnelle</p>
        </div>
      </div>
    </>
  )
}

function AlertStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Message prioritaire</h3>
        <p>
          Le message doit rester court et compréhensible sans dépendre de la
          couleur.
        </p>
        <label className="field">
          Niveau <em>*</em>
          <select>
            <option>Critique — action immédiate</option>
            <option>Avertissement</option>
            <option>Information</option>
          </select>
        </label>
        <label className="field">
          Message <em>*</em>
          <textarea defaultValue="Veuillez éviter l’accès Est du bâtiment. Utilisez l’entrée principale et suivez les indications du personnel habilité." />
        </label>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Portée et durée</h3>
        <p>
          Une expiration est obligatoire. Les lecteurs hors ligne sont signalés
          sans fausse confirmation.
        </p>
        <div className="target-list">
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Tous les niveaux</b>14 écrans ciblés
            </span>
          </label>
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Expiration automatique</b>Dans 30 minutes
            </span>
          </label>
        </div>
        <div className="warning-box">
          <Icon name="alert" />
          <span>
            <strong>1 lecteur est hors ligne</strong>
            <small>L’activation ne peut pas être garantie sur ECR-2E-02.</small>
          </span>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Aperçu plein écran</h3>
        <p>Le message interrompt les programmes ordinaires autorisés.</p>
        <div className="mini-alert">
          <Icon name="alert" size={36} />
          <small>ALERTE PRIORITAIRE</small>
          <strong>Veuillez éviter l’accès Est du bâtiment</strong>
          <span>Utilisez l’entrée principale.</span>
        </div>
      </>
    )
  if (step === 3)
    return (
      <>
        <h3>Confirmation explicite</h3>
        <p>Votre pouvoir « urgence » est actif jusqu’au 31 décembre 2026.</p>
        <div className="confirmation-box danger">
          <Icon name="alert" />
          <div>
            <strong>Interrompre les programmes sur 14 écrans</strong>
            <p>
              13 lecteurs devraient recevoir l’alerte immédiatement. 1 lecteur
              est hors ligne. Motif et identité seront audités.
            </p>
          </div>
        </div>
        <label className="field">
          Justification <em>*</em>
          <input defaultValue="Fermeture temporaire de l’accès Est signalée par les services généraux." />
        </label>
      </>
    )
  return (
    <>
      <h3>Suivi de réception</h3>
      <p>
        L’alerte est active. La « lecture déclarée » ne prouve pas la visibilité
        physique de la dalle.
      </p>
      <div className="alert-receipts">
        <div>
          <strong>14</strong>
          <span>Ciblés</span>
        </div>
        <div>
          <strong>13</strong>
          <span>Manifestes reçus</span>
        </div>
        <div>
          <strong>12</strong>
          <span>Activations déclarées</span>
        </div>
        <div>
          <strong>1</strong>
          <span>Hors ligne</span>
        </div>
      </div>
      <div className="success-box">
        <Icon name="check" />
        <span>
          <strong>Programmes ordinaires suspendus</strong>
          <small>
            Ils reprendront automatiquement à la levée ou à l’expiration.
          </small>
        </span>
      </div>
    </>
  )
}

function ReportStep({ step }: { step: number }) {
  if (step === 0)
    return (
      <>
        <h3>Périmètre du rapport</h3>
        <p>Les données seront figées à l’instant de génération.</p>
        <div className="form-grid">
          <label>
            Type
            <select>
              <option>Rapport de diffusion</option>
            </select>
          </label>
          <label>
            Période
            <select>
              <option>1–30 novembre 2026</option>
            </select>
          </label>
          <label>
            Site
            <select>
              <option>Libreville — Siège</option>
            </select>
          </label>
          <label>
            Emplacements
            <select>
              <option>Tous les niveaux</option>
            </select>
          </label>
        </div>
      </>
    )
  if (step === 1)
    return (
      <>
        <h3>Indicateurs inclus</h3>
        <p>Chaque niveau de preuve est présenté séparément.</p>
        <div className="target-list">
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Campagnes programmées</b>Volumes et cibles
            </span>
          </label>
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Manifestes reçus</b>Horodatages des lecteurs
            </span>
          </label>
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Activations déclarées</b>Sans estimation d’audience
            </span>
          </label>
          <label>
            <input type="checkbox" defaultChecked />
            <span>
              <b>Incidents associés</b>Impact et durée mesurée
            </span>
          </label>
        </div>
      </>
    )
  if (step === 2)
    return (
      <>
        <h3>Aperçu PDF</h3>
        <p>A4 paysage · données fictives · classification interne.</p>
        <PdfPreview />
      </>
    )
  return (
    <>
      <h3>Rapport en cours de génération</h3>
      <p>
        Le traitement est asynchrone. Le téléchargement restera soumis à vos
        droits.
      </p>
      <div className="export-progress">
        <span>
          <Icon name="file" size={28} />
        </span>
        <div>
          <strong>Rapport-diffusion-novembre-2026.pdf</strong>
          <small>Compilation des 14 lecteurs · 82%</small>
          <i>
            <em style={{ width: "82%" }} />
          </i>
        </div>
        <Status tone="blue">Traitement</Status>
      </div>
    </>
  )
}

function PdfPreview() {
  return (
    <div className="pdf-preview">
      <header>
        <img src="/ceeac-logo.jpg" />
        <div>
          <small>COMMISSION DE LA CEEAC</small>
          <strong>Rapport de diffusion</strong>
          <span>1–30 novembre 2026 · Site de Libreville</span>
        </div>
        <b>INTERNE</b>
      </header>
      <div className="pdf-summary">
        <span>
          <b>14</b> écrans ciblés
        </span>
        <span>
          <b>1 284</b> activations déclarées
        </span>
        <span>
          <b>96,8%</b> disponibilité lecteurs
        </span>
      </div>
      <table>
        <thead>
          <tr>
            <th>Écran</th>
            <th>Programmé</th>
            <th>Reçu</th>
            <th>Activé</th>
            <th>Dalle</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>ECR-RDC-01</td>
            <td>1 326</td>
            <td>1 301</td>
            <td>1 284</td>
            <td>Allumée</td>
          </tr>
          <tr>
            <td>ECR-2E-02</td>
            <td>1 326</td>
            <td>1 092</td>
            <td>1 071</td>
            <td>Non mesuré</td>
          </tr>
        </tbody>
      </table>
      <footer>
        Généré le 13/11/2026 à 10:52 · Africa/Libreville · Page 1 sur 4
      </footer>
    </div>
  )
}

function App() {
  const [page, setPage] = useState<Page>("dashboard")
  const [sidebar, setSidebar] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const [flow, setFlow] = useState<string | null>(null)
  const [notification, setNotification] = useState(false)
  const title = useMemo(() => pageTitles[page][0], [page])
  const go = (p: Page) => {
    setPage(p)
    setSidebar(false)
    window.scrollTo({ top: 0, behavior: "smooth" })
  }
  return (
    <div className="app-shell">
      <aside className={`sidebar ${sidebar ? "open" : ""}`}>
        <div className="brand">
          <img src="/ceeac-logo.jpg" alt="Logo officiel de la CEEAC" />
          <div>
            <strong>
              SAIE<span>—CEEAC</span>
            </strong>
            <small>Système d’information</small>
          </div>
          <button className="mobile-close" onClick={() => setSidebar(false)}>
            <Icon name="x" />
          </button>
        </div>
        <nav>
          {nav.map((g) => (
            <div className="nav-group" key={g.group}>
              <small>{g.group}</small>
              {g.items.map((item) => (
                <button
                  key={item.id}
                  className={page === item.id ? "active" : ""}
                  onClick={() => go(item.id)}
                >
                  <Icon name={item.icon} />
                  <span>{item.label}</span>
                  {item.count && <b>{item.count}</b>}
                </button>
              ))}
            </div>
          ))}
        </nav>
        <div className="sidebar-foot">
          <div className="system-state">
            <span>
              <i />
              Système opérationnel
            </span>
            <small>Dernière vérification · 10:42</small>
          </div>
          <button>
            <Icon name="info" />
            <span>Aide & documentation</span>
          </button>
        </div>
      </aside>
      {sidebar && (
        <button className="scrim" onClick={() => setSidebar(false)} />
      )}
      <div className="main-shell">
        <header className="topbar">
          <button className="menu-button" onClick={() => setSidebar(true)}>
            <Icon name="layout" />
          </button>
          <div className="breadcrumb">
            <span>SAIE-CEEAC</span>
            <Icon name="chevron" size={14} />
            <strong>{title}</strong>
          </div>
          <button className="global-search">
            <Icon name="search" />
            <span>Rechercher dans SAIE…</span>
            <kbd>⌘ K</kbd>
          </button>
          <div className="top-actions">
            <button
              className="icon-button notification"
              onClick={() => setNotification(!notification)}
            >
              <Icon name="bell" />
              <i>3</i>
            </button>
            <div className="top-divider" />
            <button
              className="profile"
              onClick={() => setProfileOpen(!profileOpen)}
            >
              <span>NK</span>
              <div>
                <strong>Nadia K.</strong>
                <small>Validatrice éditoriale</small>
              </div>
              <Icon name="chevron" size={14} />
            </button>
          </div>
          {notification && (
            <div className="notification-pop">
              <header>
                <strong>Notifications</strong>
                <button onClick={() => setNotification(false)}>
                  Tout marquer lu
                </button>
              </header>
              <button
                onClick={() => {
                  go("tasks")
                  setNotification(false)
                }}
              >
                <i />
                <span>
                  <strong>Nouvelle validation assignée</strong>
                  <small>Communiqué du Secrétariat · il y a 8 min</small>
                </span>
              </button>
              <button
                onClick={() => {
                  go("screens")
                  setNotification(false)
                }}
              >
                <i />
                <span>
                  <strong>Lecteur indisponible</strong>
                  <small>ECR-2E-02 · il y a 18 min</small>
                </span>
              </button>
              <button
                onClick={() => {
                  go("rooms")
                  setNotification(false)
                }}
              >
                <span>
                  <strong>Réservation confirmée</strong>
                  <small>Salle Congo · il y a 32 min</small>
                </span>
              </button>
            </div>
          )}
          {profileOpen && (
            <div className="profile-pop">
              <div>
                <strong>Nadia K.</strong>
                <small>Communication · périmètre Libreville</small>
              </div>
              <button>
                <Icon name="users" />
                Mon profil
              </button>
              <button>
                <Icon name="shield" />
                Sécurité & sessions
              </button>
              <button>
                <Icon name="settings" />
                Préférences
              </button>
              <hr />
              <button>
                <Icon name="arrow" />
                Se déconnecter
              </button>
            </div>
          )}
        </header>
        <main className="content-area">
          {page === "dashboard" && <Dashboard go={go} />}
          {page === "contents" && <Contents openFlow={setFlow} />}
          {page === "media" && <Media openFlow={setFlow} />}
          {page === "documents" && <Documents openFlow={setFlow} />}
          {page === "layouts" && <Layouts />}
          {page === "schedule" && <Schedule />}
          {page === "screens" && <Screens openFlow={setFlow} />}
          {page === "rooms" && <Rooms openFlow={setFlow} />}
          {page === "protocol" && <Protocol openFlow={setFlow} />}
          {page === "alerts" && <Alerts openFlow={setFlow} />}
          {page === "tasks" && <Tasks openFlow={setFlow} />}
          {page === "reports" && <Reports openFlow={setFlow} />}
          {page === "admin" && <Admin />}
          {page === "player" && <Player />}
          {page === "assumptions" && <Assumptions />}
        </main>
      </div>
      {flow && <FlowModal type={flow} onClose={() => setFlow(null)} />}
    </div>
  )
}

export default App
