# Dictionnaire de données

Généré depuis le schéma PostgreSQL 18 réel (migrations du dépôt). Classification des colonnes : **S** secret (jamais exporté ni journalisé, SEC-05), **P** donnée personnelle (GOV-02), **M** métier. Durées de conservation : propositions à fonder sur le registre des traitements avant mise en production.

## `access_links`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| target_type | character varying | non |  | M |
| target_id | uuid | non |  | M |
| classification | character varying | non |  | M |
| expires_at | timestamp with time zone | oui |  | M |
| hits | bigint | non | '0'::bigint | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `access_links_class_check` : `CHECK (((classification)::text = ANY ((ARRAY['public'::character varying, 'interne'::character varying])::text[])))`

## `agenda_items`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reservation_id | uuid | non |  | M |
| position | smallint | non |  | M |
| title | character varying | non |  | M |
| time_label | character varying | oui |  | M |
| presenter | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `alert_receipts`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| alert_id | uuid | non |  | M |
| device_id | uuid | non |  | M |
| received_at | timestamp with time zone | oui |  | M |
| activated_at | timestamp with time zone | oui |  | M |


## `alert_targets`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| alert_id | uuid | non |  | M |
| target_type | character varying | non |  | M |
| target_id | uuid | oui |  | M |


## `alerts`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| title | character varying | non |  | M |
| message | text | non |  | M |
| translations | jsonb | oui |  | M |
| severity | character varying | non |  | M |
| starts_at | timestamp with time zone | non |  | M |
| expires_at | timestamp with time zone | non |  | M |
| author_id | uuid | non |  | M |
| justification | text | non |  | M |
| status | character varying | non | 'active'::character varying | M |
| revoked_at | timestamp with time zone | oui |  | M |
| revoked_by | uuid | oui |  | M |
| revoke_reason | text | oui |  | M |
| extensions | jsonb | oui |  | M |
| target_count | smallint | non | '0'::smallint | M |
| offline_at_publish | smallint | non | '0'::smallint | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `alerts_period_check` : `CHECK ((expires_at > starts_at))`
- Contrainte `alerts_severity_check` : `CHECK (((severity)::text = ANY ((ARRAY['critical'::character varying, 'warning'::character varying, 'info'::character varying, 'exercise'::character varying])::text[])))`
- Contrainte `alerts_status_check` : `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'revoked'::character varying, 'expired'::character varying])::text[])))`

## `approvals`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| object_type | character varying | non |  | M |
| object_id | uuid | non |  | M |
| lang | character varying | oui |  | M |
| revision | integer | non | 1 | M |
| title | character varying | non |  | M |
| requested_by | uuid | oui |  | M |
| assignee_id | uuid | oui |  | M |
| backup_id | uuid | oui |  | M |
| due_at | timestamp with time zone | non |  | M |
| reminded_at | timestamp with time zone | oui |  | M |
| escalated_at | timestamp with time zone | oui |  | M |
| status | character varying | non | 'open'::character varying | M |
| decision | character varying | oui |  | M |
| reason | text | oui |  | M |
| decided_by | uuid | oui |  | M |
| decided_at | timestamp with time zone | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `approvals_status_check` : `CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'decided'::character varying, 'cancelled'::character varying])::text[])))`

## `asset_versions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| asset_id | uuid | non |  | M |
| version | integer | non |  | M |
| storage_key | character varying | non |  | M |
| original_name | character varying | non |  | M |
| declared_mime | character varying | oui |  | M |
| mime | character varying | oui |  | M |
| extension | character varying | non |  | M |
| size | bigint | non |  | M |
| sha256 | character | oui |  | M |
| width | integer | oui |  | M |
| height | integer | oui |  | M |
| duration_s | integer | oui |  | M |
| pages | integer | oui |  | M |
| text_content | text | oui |  | M |
| subtitles | jsonb | oui |  | M |
| thumbnail_key | character varying | oui |  | M |
| scan_status | character varying | non | 'quarantine'::character varying | M |
| scan_engine | character varying | oui |  | M |
| scan_detail | text | oui |  | M |
| processed_at | timestamp with time zone | oui |  | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `asset_versions_scan_check` : `CHECK (((scan_status)::text = ANY ((ARRAY['quarantine'::character varying, 'scanning'::character varying, 'ready'::character varying, 'rejected'::character varying])::text[])))`
- Contrainte `asset_versions_size_check` : `CHECK ((size > 0))`

## `assets`

Conservation : corbeille 30 jours (DOC-06)

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| title | character varying | non |  | M |
| kind | character varying | non |  | M |
| owner_structure_id | uuid | non |  | M |
| created_by | uuid | oui |  | M |
| classification | character varying | non | 'interne'::character varying | M |
| language | character varying | oui |  | M |
| category_id | uuid | oui |  | M |
| status | character varying | non | 'quarantine'::character varying | M |
| current_version_id | uuid | oui |  | M |
| trashed_at | timestamp with time zone | oui |  | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `assets_class_check` : `CHECK (((classification)::text = ANY ((ARRAY['public'::character varying, 'interne'::character varying, 'restreint'::character varying])::text[])))`
- Contrainte `assets_kind_check` : `CHECK (((kind)::text = ANY ((ARRAY['image'::character varying, 'video'::character varying, 'pdf'::character varying, 'office'::character varying])::text[])))`
- Contrainte `assets_status_check` : `CHECK (((status)::text = ANY ((ARRAY['quarantine'::character varying, 'scanning'::character varying, 'ready'::character varying, 'rejected'::character varying, 'trashed'::character varying])::text[])))`

## `audit_logs`

Conservation : 12 mois (SEC-04, à valider GOV-02)

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | bigint | non | nextval('audit_logs_id_seq'::regclass) | M |
| occurred_at | timestamp with time zone | non | CURRENT_TIMESTAMP | M |
| actor_id | uuid | oui |  | M |
| actor_type | character varying | non | 'user'::character varying | M |
| actor_label | character varying | oui |  | P |
| actor_role | character varying | oui |  | M |
| power | character varying | oui |  | M |
| action | character varying | non |  | M |
| object_type | character varying | oui |  | M |
| object_id | character varying | oui |  | M |
| object_label | character varying | oui |  | M |
| lang | character varying | oui |  | M |
| version | character varying | oui |  | M |
| reason | text | oui |  | M |
| outcome | character varying | non | 'success'::character varying | M |
| ip | character varying | oui |  | P |
| request_id | character varying | oui |  | M |
| before | jsonb | oui |  | M |
| after | jsonb | oui |  | M |


## `buildings`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| site_id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `campaign_items`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| campaign_id | uuid | non |  | M |
| content_id | uuid | non |  | M |
| position | smallint | non |  | M |
| duration_s | smallint | oui |  | M |
| version_map | jsonb | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `campaign_languages`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| campaign_id | uuid | non |  | M |
| lang | character varying | non |  | M |
| rank | smallint | non |  | M |


## `campaign_targets`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| campaign_id | uuid | non |  | M |
| target_type | character varying | non |  | M |
| target_id | uuid | non |  | M |

- Contrainte `campaign_targets_type_check` : `CHECK (((target_type)::text = ANY ((ARRAY['screen'::character varying, 'group'::character varying, 'zone'::character varying, 'floor'::character varying])::text[])))`

## `campaigns`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| name | character varying | non |  | P |
| status | character varying | non | 'draft'::character varying | M |
| priority | smallint | non | '50'::smallint | M |
| starts_at | timestamp with time zone | non |  | M |
| ends_at | timestamp with time zone | non |  | M |
| days | jsonb | oui |  | M |
| time_from | time without time zone | oui |  | M |
| time_to | time without time zone | oui |  | M |
| slide_duration_s | smallint | non | '12'::smallint | M |
| created_by | uuid | non |  | M |
| published_at | timestamp with time zone | oui |  | M |
| published_by | uuid | oui |  | M |
| suspended_at | timestamp with time zone | oui |  | M |
| suspend_reason | text | oui |  | M |
| version | integer | non | 1 | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `campaigns_duration_check` : `CHECK ((slide_duration_s >= 5))`
- Contrainte `campaigns_period_check` : `CHECK ((ends_at > starts_at))`
- Contrainte `campaigns_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'scheduled'::character varying, 'active'::character varying, 'suspended'::character varying, 'expired'::character varying, 'archived'::character varying])::text[])))`

## `checklist_items`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| occurrence_id | uuid | non |  | M |
| phase | character varying | non |  | M |
| label | character varying | non |  | M |
| done_at | timestamp with time zone | oui |  | M |
| done_by | uuid | oui |  | M |
| note | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `content_categories`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| default_duration_s | smallint | non | '12'::smallint | M |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `content_languages`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| content_id | uuid | non |  | M |
| lang | character varying | non |  | M |
| revision | integer | non | 1 | M |
| title | character varying | non |  | M |
| body | text | oui |  | M |
| extra | jsonb | oui |  | M |
| subtitles_asset_version_id | uuid | oui |  | M |
| status | character varying | non | 'draft'::character varying | M |
| overflow | boolean | non | false | M |
| submitted_at | timestamp with time zone | oui |  | M |
| submitted_by | uuid | oui |  | M |
| last_return_reason | text | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `content_languages_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'submitted'::character varying, 'approved'::character varying, 'returned'::character varying])::text[])))`

## `content_versions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| content_id | uuid | non |  | M |
| lang | character varying | non |  | M |
| revision | integer | non |  | M |
| payload | jsonb | non |  | S |
| classification | character varying | non |  | M |
| template_id | uuid | oui |  | M |
| author_id | uuid | non |  | M |
| approved_by | uuid | non |  | M |
| approved_at | timestamp with time zone | non |  | M |
| payload_hash | character | non |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `contents`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| title | character varying | non |  | M |
| kind | character varying | non | 'standard'::character varying | M |
| category_id | uuid | non |  | M |
| owner_structure_id | uuid | non |  | M |
| author_id | uuid | non |  | M |
| classification | character varying | non | 'interne'::character varying | M |
| priority | smallint | non | '50'::smallint | M |
| template_id | uuid | oui |  | M |
| media | jsonb | oui |  | M |
| qr | jsonb | oui |  | M |
| proposed_targets | jsonb | oui |  | M |
| valid_from | timestamp with time zone | oui |  | M |
| expires_at | timestamp with time zone | oui |  | M |
| is_permanent | boolean | non | false | M |
| status | character varying | non | 'draft'::character varying | M |
| occurrence_id | uuid | oui |  | M |
| delegation | jsonb | oui |  | M |
| archived_at | timestamp with time zone | oui |  | M |
| version | integer | non | 1 | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `contents_class_check` : `CHECK (((classification)::text = ANY ((ARRAY['public'::character varying, 'interne'::character varying, 'restreint'::character varying])::text[])))`
- Contrainte `contents_expiry_check` : `CHECK ((is_permanent OR (expires_at IS NOT NULL) OR ((status)::text = 'draft'::text)))`
- Contrainte `contents_kind_check` : `CHECK (((kind)::text = ANY ((ARRAY['standard'::character varying, 'welcome'::character varying, 'fallback'::character varying])::text[])))`
- Contrainte `contents_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'submitted'::character varying, 'approved'::character varying, 'scheduled'::character varying, 'broadcasting'::character varying, 'expired'::character varying, 'archived'::character varying, 'suspended'::character varying])::text[])))`

## `delivery_attempts`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| notification_id | uuid | non |  | M |
| channel | character varying | non |  | M |
| status | character varying | non | 'pending'::character varying | M |
| attempts | smallint | non | '0'::smallint | M |
| last_error | text | oui |  | M |
| sent_at | timestamp with time zone | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `device_receipts`

Conservation : 12 mois

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| event_id | uuid | non |  | M |
| device_id | uuid | non |  | M |
| kind | character varying | non |  | M |
| manifest_version | integer | oui |  | M |
| ref_type | character varying | oui |  | M |
| ref_id | character varying | oui |  | M |
| lang | character varying | oui |  | M |
| occurred_at | timestamp with time zone | non |  | M |
| received_at | timestamp with time zone | non |  | M |
| payload | jsonb | oui |  | S |


## `device_screenshots`

Conservation : 7 jours (PLY-11)

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| device_id | uuid | non |  | M |
| requested_by | uuid | oui |  | M |
| requested_at | timestamp with time zone | non |  | M |
| captured_at | timestamp with time zone | oui |  | M |
| storage_key | character varying | oui |  | M |
| status | character varying | non | 'requested'::character varying | M |
| expires_at | timestamp with time zone | non |  | M |


## `device_telemetry`

Conservation : 30 jours

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | bigint | non | nextval('device_telemetry_id_seq'::regcl | M |
| device_id | uuid | non |  | M |
| recorded_at | timestamp with time zone | non |  | M |
| payload | jsonb | non |  | S |


## `devices`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| screen_id | uuid | oui |  | M |
| hardware_id | character varying | non |  | M |
| status | character varying | non | 'active'::character varying | M |
| app_version | character varying | oui |  | M |
| platform | character varying | oui |  | M |
| reported_resolution | character varying | oui |  | M |
| last_seen_at | timestamp with time zone | oui |  | M |
| last_sync_at | timestamp with time zone | oui |  | M |
| network_status | character varying | non | 'unknown'::character varying | M |
| storage | jsonb | oui |  | M |
| clock_skew_ms | integer | oui |  | M |
| active_manifest_version | integer | oui |  | M |
| current_playback | jsonb | oui |  | M |
| last_errors | jsonb | oui |  | M |
| cache_inventory | jsonb | oui |  | M |
| paired_at | timestamp with time zone | oui |  | M |
| paired_by | uuid | oui |  | M |
| revoked_at | timestamp with time zone | oui |  | M |
| revoked_by | uuid | oui |  | M |
| revoke_reason | character varying | oui |  | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `devices_network_check` : `CHECK (((network_status)::text = ANY ((ARRAY['unknown'::character varying, 'online'::character varying, 'suspect'::character varying, 'unavailable'::character varying])::text[])))`
- Contrainte `devices_status_check` : `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'revoked'::character varying])::text[])))`

## `display_zones`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| max_classification | character varying | non | 'public'::character varying | M |
| audio_allowed | boolean | non | false | M |
| audio_max_volume | smallint | non | '0'::smallint | M |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `display_zones_class_check` : `CHECK (((max_classification)::text = ANY ((ARRAY['public'::character varying, 'interne'::character varying])::text[])))`
- Contrainte `display_zones_volume_check` : `CHECK (((audio_max_volume >= 0) AND (audio_max_volume <= 100)))`

## `document_audiences`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| document_id | uuid | non |  | M |
| structure_id | uuid | non |  | M |


## `document_reads`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| document_id | uuid | non |  | M |
| document_version_id | uuid | non |  | M |
| user_id | uuid | non |  | M |
| first_opened_at | timestamp with time zone | non |  | M |


## `document_versions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| document_id | uuid | non |  | M |
| version | integer | non |  | M |
| asset_version_id | uuid | non |  | M |
| notes | text | oui |  | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `documents`

Conservation : politique documentaire (DOC-06)

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| title | character varying | non |  | M |
| summary | text | oui |  | M |
| category_id | uuid | oui |  | M |
| owner_structure_id | uuid | non |  | M |
| author_id | uuid | oui |  | M |
| language | character varying | non | 'fr'::character varying | M |
| keywords | jsonb | oui |  | M |
| classification | character varying | non | 'interne'::character varying | M |
| valid_from | date | oui |  | M |
| expires_at | date | oui |  | M |
| status | character varying | non | 'draft'::character varying | M |
| current_version_id | uuid | oui |  | M |
| published_at | timestamp with time zone | oui |  | M |
| unpublished_at | timestamp with time zone | oui |  | M |
| trashed_at | timestamp with time zone | oui |  | M |
| search_text | text | oui |  | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |
| search_vector | tsvector | oui |  | M |

- Contrainte `documents_class_check` : `CHECK (((classification)::text = ANY ((ARRAY['public'::character varying, 'interne'::character varying, 'restreint'::character varying])::text[])))`
- Contrainte `documents_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying, 'unpublished'::character varying, 'trashed'::character varying])::text[])))`

## `dynamic_sources`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| type | character varying | non |  | M |
| url | character varying | oui |  | M |
| config | jsonb | oui |  | M |
| is_active | boolean | non | false | M |
| frequency_minutes | smallint | non | '30'::smallint | M |
| last_fetched_at | timestamp with time zone | oui |  | M |
| last_status | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `equipment`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `exports`

Conservation : 72 h après génération

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| type | character varying | non |  | M |
| format | character varying | non |  | M |
| params | jsonb | oui |  | M |
| status | character varying | non | 'pending'::character varying | M |
| progress | smallint | non | '0'::smallint | M |
| file_key | character varying | oui |  | M |
| file_name | character varying | oui |  | M |
| size | bigint | oui |  | M |
| error | text | oui |  | M |
| classification | character varying | non | 'interne'::character varying | M |
| requested_by | uuid | non |  | M |
| completed_at | timestamp with time zone | oui |  | M |
| expires_at | timestamp with time zone | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `exports_status_check` : `CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'ready'::character varying, 'failed'::character varying, 'expired'::character varying, 'cancelled'::character varying])::text[])))`

## `fallback_contents`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| zone_id | uuid | non |  | M |
| content_version_id | uuid | non |  | M |
| valid_until | timestamp with time zone | non |  | M |
| is_active | boolean | non | true | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `floors`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| building_id | uuid | non |  | M |
| code | character varying | non |  | M |
| label | character varying | non |  | M |
| level | smallint | non |  | M |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `group_members`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| group_id | uuid | non |  | M |
| screen_id | uuid | non |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `holidays`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| date | date | non |  | M |
| label | character varying | non |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `idempotency_keys`

Conservation : 48 h

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| scope | character varying | non |  | M |
| key | character varying | non |  | M |
| request_hash | character | non |  | M |
| response_status | smallint | oui |  | M |
| response_body | jsonb | oui |  | M |
| created_at | timestamp with time zone | non | CURRENT_TIMESTAMP | M |


## `import_runs`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| type | character varying | non |  | M |
| file_name | character varying | non |  | M |
| match_key | character varying | non |  | M |
| status | character varying | non |  | M |
| summary | jsonb | oui |  | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `incidents`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| screen_id | uuid | oui |  | M |
| device_id | uuid | oui |  | M |
| kind | character varying | non |  | M |
| severity | character varying | non |  | M |
| status | character varying | non | 'open'::character varying | M |
| title | character varying | non |  | M |
| description | text | oui |  | M |
| impact | character varying | oui |  | M |
| detected_at | timestamp with time zone | non |  | M |
| acknowledged_at | timestamp with time zone | oui |  | M |
| resolved_at | timestamp with time zone | oui |  | M |
| closed_at | timestamp with time zone | oui |  | M |
| assignee_id | uuid | oui |  | M |
| events | jsonb | oui |  | M |
| is_automatic | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `incidents_severity_check` : `CHECK (((severity)::text = ANY ((ARRAY['critical'::character varying, 'major'::character varying, 'minor'::character varying])::text[])))`
- Contrainte `incidents_status_check` : `CHECK (((status)::text = ANY ((ARRAY['open'::character varying, 'acknowledged'::character varying, 'resolved'::character varying, 'closed'::character varying])::text[])))`

## `languages`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| is_active | boolean | non | false | M |
| sort | smallint | non | '0'::smallint | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `locations`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| floor_id | uuid | non |  | M |
| zone_id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| description | text | oui |  | M |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `manifests`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| device_id | uuid | non |  | M |
| version | integer | non |  | M |
| etag | character | non |  | M |
| payload | jsonb | non |  | S |
| valid_until | timestamp with time zone | non |  | M |
| generated_at | timestamp with time zone | non |  | M |


## `mobile_resources`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| type | character varying | non |  | M |
| manager_id | uuid | oui |  | M |
| status | character varying | non | 'active'::character varying | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `notifications`

Conservation : 12 mois

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| user_id | uuid | non |  | M |
| event | character varying | non |  | M |
| title | character varying | non |  | M |
| body | character varying | oui |  | M |
| link | character varying | oui |  | M |
| object_type | character varying | oui |  | M |
| object_id | uuid | oui |  | M |
| dedup_key | character varying | non |  | M |
| read_at | timestamp with time zone | oui |  | M |
| created_at | timestamp with time zone | non | CURRENT_TIMESTAMP | M |


## `outbox_events`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| type | character varying | non |  | M |
| payload | jsonb | non |  | S |
| created_at | timestamp with time zone | non | CURRENT_TIMESTAMP | M |
| dispatched_at | timestamp with time zone | oui |  | M |
| attempts | smallint | non | '0'::smallint | M |
| last_error | text | oui |  | M |


## `pairing_codes`

Conservation : 24 h si non utilisé

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| poll_secret_hash | character varying | non |  | S |
| hardware_id | character varying | non |  | M |
| device_info | jsonb | oui |  | M |
| expires_at | timestamp with time zone | non |  | M |
| used_at | timestamp with time zone | oui |  | M |
| device_id | uuid | oui |  | M |
| issued_token | text | oui |  | S |
| token_collected_at | timestamp with time zone | oui |  | M |
| created_ip | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `password_reset_tokens`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| email | character varying | non |  | P |
| token | character varying | non |  | S |
| created_at | timestamp without time zone | oui |  | M |


## `permission_role`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| role_id | uuid | non |  | M |
| permission_id | uuid | non |  | M |


## `permissions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| group | character varying | non |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `personal_access_tokens`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | bigint | non | nextval('personal_access_tokens_id_seq': | M |
| tokenable_type | character varying | non |  | M |
| tokenable_id | uuid | non |  | M |
| name | text | non |  | P |
| token | character varying | non |  | S |
| abilities | text | oui |  | M |
| last_used_at | timestamp without time zone | oui |  | M |
| expires_at | timestamp without time zone | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `push_subscriptions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| user_id | uuid | non |  | M |
| endpoint | text | non |  | M |
| keys | jsonb | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `reservation_documents`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| reservation_id | uuid | non |  | M |
| document_id | uuid | non |  | M |


## `reservation_occurrences`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reservation_id | uuid | non |  | M |
| room_id | uuid | non |  | M |
| sequence | smallint | non | '1'::smallint | M |
| starts_at | timestamp with time zone | non |  | M |
| ends_at | timestamp with time zone | non |  | M |
| blocking_start | timestamp with time zone | non |  | M |
| blocking_end | timestamp with time zone | non |  | M |
| status | character varying | non |  | M |
| hold_until | timestamp with time zone | oui |  | M |
| decided_by | uuid | oui |  | M |
| decided_at | timestamp with time zone | oui |  | M |
| decision_reason | character varying | oui |  | M |
| cancel_reason | character varying | oui |  | M |
| no_show_reason | character varying | oui |  | M |
| no_show_by | uuid | oui |  | M |
| no_show_at | timestamp with time zone | oui |  | M |
| capacity_override | boolean | non | false | M |
| capacity_override_reason | character varying | oui |  | M |
| version | integer | non | 1 | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `occurrences_no_overlap` : `EXCLUDE USING gist (room_id WITH =, tstzrange(blocking_start, blocking_end, '[)'::text) WITH &&) WHERE (((status)::text = ANY ((ARRAY['pending'::character varying, 'confirmed'::character varying, 'in_progress'::character varying])::text[])))`
- Contrainte `occurrences_period_check` : `CHECK (((ends_at > starts_at) AND (blocking_start <= starts_at) AND (blocking_end >= ends_at)))`
- Contrainte `occurrences_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending'::character varying, 'confirmed'::character varying, 'refused'::character varying, 'cancelled'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'no_show'::character varying])::text[])))`

## `reservations`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| organizer_id | uuid | non |  | M |
| structure_id | uuid | oui |  | M |
| subject | character varying | non |  | M |
| description | text | oui |  | M |
| confidential | boolean | non | false | M |
| participant_count | integer | non |  | M |
| internal_participants | jsonb | oui |  | P |
| external_participants | jsonb | oui |  | P |
| recurrence | jsonb | oui |  | M |
| is_priority | boolean | non | false | M |
| created_by | uuid | oui |  | M |
| version | integer | non | 1 | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `reservations_participants_check` : `CHECK ((participant_count > 0))`

## `resource_bookings`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| resource_id | uuid | non |  | M |
| occurrence_id | uuid | non |  | M |
| blocking_start | timestamp with time zone | non |  | M |
| blocking_end | timestamp with time zone | non |  | M |
| status | character varying | non | 'active'::character varying | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `resource_bookings_no_overlap` : `EXCLUDE USING gist (resource_id WITH =, tstzrange(blocking_start, blocking_end, '[)'::text) WITH &&) WHERE (((status)::text = 'active'::text))`

## `role_assignments`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| user_id | uuid | non |  | M |
| role_id | uuid | non |  | M |
| structure_id | uuid | oui |  | M |
| site_id | uuid | oui |  | M |
| zone_id | uuid | oui |  | M |
| languages | jsonb | oui |  | M |
| starts_at | timestamp with time zone | non |  | M |
| ends_at | timestamp with time zone | oui |  | M |
| revoked_at | timestamp with time zone | oui |  | M |
| granted_by | uuid | oui |  | M |
| reason | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `role_assignments_period_check` : `CHECK (((ends_at IS NULL) OR (ends_at > starts_at)))`

## `role_extensions`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| user_id | uuid | non |  | M |
| power | character varying | non |  | M |
| scope | jsonb | oui |  | M |
| starts_at | timestamp with time zone | non |  | M |
| ends_at | timestamp with time zone | non |  | M |
| revoked_at | timestamp with time zone | oui |  | M |
| granted_by | uuid | oui |  | M |
| reason | character varying | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `role_extensions_period_check` : `CHECK ((ends_at > starts_at))`
- Contrainte `role_extensions_power_check` : `CHECK (((power)::text = ANY ((ARRAY['urgence'::character varying, 'modeles'::character varying, 'prestations'::character varying])::text[])))`

## `roles`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| description | text | oui |  | M |
| is_system | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `room_blocks`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| room_id | uuid | non |  | M |
| starts_at | timestamp with time zone | non |  | M |
| ends_at | timestamp with time zone | non |  | M |
| kind | character varying | non | 'technical'::character varying | M |
| reason | character varying | non |  | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `room_blocks_kind_check` : `CHECK (((kind)::text = ANY ((ARRAY['technical'::character varying, 'institutional'::character varying])::text[])))`
- Contrainte `room_blocks_period_check` : `CHECK ((ends_at > starts_at))`

## `room_equipment`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| room_id | uuid | non |  | M |
| equipment_id | uuid | non |  | M |
| quantity | smallint | non | '1'::smallint | M |


## `rooms`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| floor_id | uuid | non |  | M |
| capacity | integer | non |  | M |
| location_text | character varying | oui |  | M |
| photos | jsonb | oui |  | M |
| manager_id | uuid | oui |  | M |
| approval_mode | character varying | non | 'auto'::character varying | M |
| min_minutes | smallint | non | '30'::smallint | M |
| max_minutes | smallint | non | '600'::smallint | M |
| lead_days | smallint | non | '90'::smallint | M |
| prep_minutes | smallint | non | '0'::smallint | M |
| cleanup_minutes | smallint | non | '0'::smallint | M |
| open_from | time without time zone | non | '07:30:00'::time without time zone | M |
| open_to | time without time zone | non | '19:00:00'::time without time zone | M |
| pending_hold_hours | smallint | non | '24'::smallint | M |
| auto_release_enabled | boolean | non | false | M |
| status | character varying | non | 'active'::character varying | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `rooms_capacity_check` : `CHECK ((capacity > 0))`
- Contrainte `rooms_duration_check` : `CHECK ((max_minutes >= min_minutes))`
- Contrainte `rooms_hours_check` : `CHECK ((open_to > open_from))`
- Contrainte `rooms_mode_check` : `CHECK (((approval_mode)::text = ANY ((ARRAY['auto'::character varying, 'manual'::character varying])::text[])))`
- Contrainte `rooms_status_check` : `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))`

## `screen_groups`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| description | text | oui |  | M |
| structure_id | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `screens`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| kind | character varying | non | 'display'::character varying | M |
| location_id | uuid | non |  | M |
| room_id | uuid | oui |  | M |
| orientation | character varying | non | 'landscape'::character varying | M |
| resolution | character varying | non | '1920x1080'::character varying | M |
| operating_window | jsonb | oui |  | M |
| window_override_reason | character varying | oui |  | M |
| audio_enabled | boolean | non | false | M |
| control_channel | character varying | non | 'none'::character varying | M |
| panel_state | character varying | oui |  | M |
| panel_state_at | timestamp with time zone | oui |  | M |
| status | character varying | non | 'active'::character varying | M |
| notes | text | oui |  | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `screens_channel_check` : `CHECK (((control_channel)::text = ANY ((ARRAY['none'::character varying, 'cec'::character varying, 'rs232'::character varying, 'api'::character varying])::text[])))`
- Contrainte `screens_kind_check` : `CHECK (((kind)::text = ANY ((ARRAY['display'::character varying, 'room_panel'::character varying, 'orientation'::character varying])::text[])))`
- Contrainte `screens_orientation_check` : `CHECK (((orientation)::text = ANY ((ARRAY['landscape'::character varying, 'portrait'::character varying])::text[])))`
- Contrainte `screens_status_check` : `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))`

## `service_requests`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| occurrence_id | uuid | non |  | M |
| service_type_id | uuid | non |  | M |
| details | text | oui |  | M |
| status | character varying | non | 'requested'::character varying | M |
| assignee_id | uuid | oui |  | M |
| due_at | timestamp with time zone | oui |  | M |
| response_note | text | oui |  | M |
| updated_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `service_requests_status_check` : `CHECK (((status)::text = ANY ((ARRAY['requested'::character varying, 'accepted'::character varying, 'clarification'::character varying, 'ready'::character varying, 'impossible'::character varying, 'cancelled'::character varying])::text[])))`

## `service_types`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `sessions`

Conservation : durée de session

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | character varying | non |  | M |
| user_id | uuid | oui |  | M |
| ip_address | character varying | oui |  | P |
| user_agent | text | oui |  | P |
| payload | text | non |  | S |
| last_activity | integer | non |  | M |


## `settings`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| key | character varying | non |  | M |
| value | jsonb | oui |  | M |
| is_secret | boolean | non | false | M |
| updated_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `sites`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| timezone | character varying | non | 'Africa/Libreville'::character varying | M |
| address | character varying | oui |  | M |
| is_active | boolean | non | true | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `source_snapshots`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| source_id | uuid | non |  | M |
| fetched_at | timestamp with time zone | non |  | M |
| payload | jsonb | oui |  | S |
| status | character varying | non |  | M |


## `structures`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| type | character varying | non |  | M |
| parent_id | uuid | oui |  | M |
| is_active | boolean | non | true | M |
| storage_quota_mb | bigint | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |
| floor_id | uuid | oui |  | M |
| show_in_orientation | boolean | non | true | M |

- Contrainte `structures_type_check` : `CHECK (((type)::text = ANY ((ARRAY['cabinet'::character varying, 'departement'::character varying, 'direction'::character varying, 'service'::character varying, 'autre'::character varying])::text[])))`

## `templates`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| code | character varying | non |  | M |
| name | character varying | non |  | P |
| orientation | character varying | non |  | M |
| purpose | character varying | non | 'content'::character varying | M |
| layout | jsonb | non |  | M |
| version | integer | non | 1 | M |
| status | character varying | non | 'active'::character varying | M |
| is_institutional | boolean | non | false | M |
| created_by | uuid | oui |  | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `templates_orientation_check` : `CHECK (((orientation)::text = ANY ((ARRAY['landscape'::character varying, 'portrait'::character varying])::text[])))`
- Contrainte `templates_status_check` : `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'archived'::character varying])::text[])))`

## `users`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| name | character varying | non |  | P |
| email | character varying | non |  | P |
| email_verified_at | timestamp without time zone | oui |  | M |
| password | character varying | non |  | S |
| structure_id | uuid | oui |  | M |
| backup_user_id | uuid | oui |  | M |
| title | character varying | oui |  | M |
| locale | character varying | non | 'fr'::character varying | M |
| timezone | character varying | non | 'Africa/Libreville'::character varying | M |
| status | character varying | non | 'active'::character varying | M |
| source | character varying | non | 'local'::character varying | M |
| external_id | character varying | oui |  | M |
| mfa_secret | text | oui |  | S |
| mfa_enabled_at | timestamp without time zone | oui |  | M |
| mfa_recovery_codes | text | oui |  | S |
| must_change_password | boolean | non | false | M |
| failed_logins | smallint | non | '0'::smallint | M |
| locked_until | timestamp without time zone | oui |  | M |
| last_login_at | timestamp without time zone | oui |  | M |
| last_activity_at | timestamp without time zone | oui |  | M |
| notification_preferences | jsonb | oui |  | M |
| is_demo | boolean | non | false | M |
| remember_token | character varying | oui |  | S |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |

- Contrainte `users_status_check` : `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'suspended'::character varying, 'disabled'::character varying])::text[])))`

## `visits`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| reference | character varying | non |  | M |
| name | character varying | non |  | P |
| institution | character varying | oui |  | M |
| visitors_count | smallint | non | '1'::smallint | M |
| occurrence_id | uuid | oui |  | M |
| content_id | uuid | oui |  | M |
| languages | jsonb | oui |  | M |
| flags | jsonb | oui |  | M |
| status | character varying | non | 'draft'::character varying | M |
| notes | text | oui |  | M |
| created_by | uuid | oui |  | M |
| is_demo | boolean | non | false | M |
| created_at | timestamp without time zone | oui |  | M |
| updated_at | timestamp without time zone | oui |  | M |


## `workflow_events`

Conservation : durée de vie de l’objet métier

| Colonne | Type | Null | Défaut | Classe |
| --- | --- | --- | --- | --- |
| id | uuid | non |  | M |
| object_type | character varying | non |  | M |
| object_id | uuid | non |  | M |
| action | character varying | non |  | M |
| from_status | character varying | oui |  | M |
| to_status | character varying | oui |  | M |
| lang | character varying | oui |  | M |
| revision | integer | oui |  | M |
| actor_id | uuid | oui |  | M |
| actor_role | character varying | oui |  | M |
| reason | text | oui |  | M |
| created_at | timestamp with time zone | non | CURRENT_TIMESTAMP | M |


