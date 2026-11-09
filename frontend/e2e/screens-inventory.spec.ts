import { expect, test } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Module « Gestion des écrans » : inventaire et localisation (§5, §6). Import CSV par l'interface (simulation puis
 * confirmation), fiche complète, déplacement historisé avec motif, code QR, filtre « archivés » et archivage final.
 */
test('inventaire : import CSV, fiche, déplacement historisé, code QR et archivage', async ({ page, request }) => {
  test.setTimeout(240_000)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  // Deux emplacements d'un même niveau, désignés par leurs codes (bâtiment, niveau, emplacement) comme dans le fichier d'import.
  const byFloor = new Map<string, { id: string; code: string; floor_id: string }[]>()
  for (const l of refs.locations) byFloor.set(l.floor_id, [...(byFloor.get(l.floor_id) ?? []), l])
  const [floorId, locations] = [...byFloor.entries()].find(([, ls]) => ls.length >= 2)!
  const floor = refs.floors.find((f: { id: string }) => f.id === floorId)
  const building = refs.buildings.find((b: { id: string }) => b.id === floor.building_id)
  const code = `ECR-E2E-INV-${Date.now().toString().slice(-6)}`
  const csv = `﻿code;name;kind;building_code;floor_code;location_code;orientation;resolution;technology;supplier;order_reference;warranty_until\n${code};Écran importé (E2E);display;${building.code};${floor.code};${locations[0].code};landscape;1920x1080;led;Fournisseur E2E;BC-E2E-01;2029-12-31\n`

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto('/affichage/ecrans')
  await page.getByRole('button', { name: 'Importer' }).click()
  const dialog = page.getByRole('dialog', { name: 'Importer des écrans' })
  await dialog.locator('input[type=file]').setInputFiles({ name: 'ecrans.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') })
  await dialog.getByRole('button', { name: 'Simuler' }).click()
  await expect(dialog.getByText('Simulation sans écriture')).toBeVisible()
  await expect(dialog.getByRole('cell', { name: code })).toBeVisible()
  expect((await api('GET', `/screens?q=${code}`)).body.data).toHaveLength(0)
  await dialog.getByRole('button', { name: 'Confirmer l’import' }).click()
  await expect(dialog.getByText('Import enregistré')).toBeVisible()
  await dialog.getByRole('button', { name: 'Fermer', exact: true }).last().click()

  const screen = (await api('GET', `/screens?q=${code}`)).body.data[0]
  expect(screen.hardware.technology).toBe('led')
  await page.goto(`/affichage/ecrans/${screen.id}`)
  await expect(page.getByText('Fournisseur E2E')).toBeVisible()
  await expect(page.getByText('BC-E2E-01')).toBeVisible()

  // Déplacement avec motif, historisé (RG-011).
  await page.getByRole('button', { name: 'Modifier l’écran' }).click()
  const edit = page.getByRole('dialog', { name: 'Modifier l’écran' })
  await edit.getByRole('combobox', { name: 'Emplacement (obligatoire)' }).selectOption(locations[1].id)
  await edit.getByLabel('Motif du déplacement').fill('Réaménagement (test E2E)')
  await edit.getByRole('button', { name: 'Enregistrer' }).click()
  await expect(edit).toHaveCount(0)
  await expect(page.getByRole('cell', { name: /Réaménagement \(test E2E\)/ })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'actuel' })).toHaveCount(1)

  // Code QR de la fiche.
  await page.getByRole('button', { name: 'Générer le code QR' }).click()
  await expect(page.getByRole('img', { name: `Code QR de la fiche de l’écran ${code}` })).toBeVisible()
  await page.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/fiche-ecran-inventaire.png', fullPage: true })

  // Archivage, puis visible seulement avec le filtre « Archivés ».
  await page.getByRole('button', { name: 'Archiver' }).click()
  const confirm = page.getByRole('dialog', { name: 'Archiver l’écran' })
  await confirm.getByRole('textbox').fill('Fin du test E2E d’inventaire')
  await confirm.getByRole('button', { name: 'Archiver définitivement' }).click()
  await expect(page.getByText(/Écran archivé le/)).toBeVisible()
  expect((await api('GET', `/screens?q=${code}`)).body.data).toHaveLength(0)
  expect((await api('GET', `/screens?q=${code}&status=archived`)).body.data).toHaveLength(1)
})
