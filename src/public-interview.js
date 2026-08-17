/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The candidate-facing slot-picking page. Framework-free on purpose: it
 * loads fast, works without an account and shows all times in the
 * candidate's own timezone.
 */
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import './public-interview.css'

const root = document.getElementById('recruiting-public')
const token = loadState('recruiting', 'publicToken', '')
let data = loadState('recruiting', 'publicInterview', null)

const formatter = new Intl.DateTimeFormat(undefined, {
	weekday: 'long',
	year: 'numeric',
	month: 'long',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
})
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone

const el = (tag, attrs = {}, children = []) => {
	const node = document.createElement(tag)
	Object.entries(attrs).forEach(([key, value]) => {
		if (key === 'text') {
			node.textContent = value
		} else if (key.startsWith('on')) {
			node.addEventListener(key.slice(2), value)
		} else {
			node.setAttribute(key, value)
		}
	})
	children.forEach((child) => node.appendChild(child))
	return node
}

function render(errorText = '') {
	root.textContent = ''
	if (data === null) {
		return
	}
	const card = el('div', { class: 'rp-card' })

	card.appendChild(el('h2', { text: t('recruiting', 'Hello {name}!', { name: data.candidateName }) }))
	const what = data.openingTitle
		? t('recruiting', '{title} — your interview for the {opening} position', { title: data.title, opening: data.openingTitle })
		: data.title
	card.appendChild(el('p', { class: 'rp-meta', text: what + ' · ' + t('recruiting', '{minutes} minutes', { minutes: data.durationMin }) }))

	if (data.status === 'confirmed') {
		card.appendChild(el('p', { class: 'rp-success', text: '🎉' }))
		card.appendChild(el('p', {
			text: t('recruiting', 'Your interview is confirmed for {date}.', { date: formatter.format(new Date(data.startAt)) }),
		}))
		if (data.talkLink) {
			card.appendChild(el('a', {
				class: 'rp-talk-link',
				href: data.talkLink,
				text: t('recruiting', 'Join the video call'),
			}))
			card.appendChild(el('p', { class: 'rp-meta', text: t('recruiting', 'The link also works on the day of the interview — no installation needed.') }))
		} else if (data.location) {
			card.appendChild(el('p', { class: 'rp-meta', text: t('recruiting', 'Location: {location}', { location: data.location }) }))
		}
		card.appendChild(el('a', {
			class: 'rp-ics-link',
			href: generateUrl('/apps/recruiting/i/{token}/event.ics', { token }),
			text: t('recruiting', '📅 Add to my calendar'),
		}))
	} else if (data.status === 'cancelled') {
		card.appendChild(el('p', { text: t('recruiting', 'This interview was cancelled. Please contact the recruiting team if you have questions.') }))
	} else if (data.slots.length === 0) {
		card.appendChild(el('p', { text: t('recruiting', 'All proposed times have passed. Please contact the recruiting team for new options.') }))
	} else {
		card.appendChild(el('p', { text: t('recruiting', 'Please pick the time that suits you best:') }))
		const list = el('ul', { class: 'rp-slots' })
		data.slots.forEach((slot) => {
			const input = el('input', { type: 'radio', name: 'slot', value: String(slot.id) })
			list.appendChild(el('li', {}, [
				el('label', { class: 'rp-slot' }, [
					input,
					el('span', { text: formatter.format(new Date(slot.startAt)) }),
				]),
			]))
		})
		card.appendChild(list)
		card.appendChild(el('p', { class: 'rp-tz', text: t('recruiting', 'Times are shown in your timezone ({timezone}).', { timezone }) }))
		if (errorText) {
			card.appendChild(el('p', { class: 'rp-error', text: errorText }))
		}
		card.appendChild(el('button', {
			class: 'rp-confirm',
			text: t('recruiting', 'Confirm this time'),
			onclick: confirm,
		}))
	}
	root.appendChild(card)
}

async function confirm(event) {
	const selected = root.querySelector('input[name="slot"]:checked')
	if (!selected) {
		render(t('recruiting', 'Please pick a time first.'))
		return
	}
	const button = event.target
	button.disabled = true
	try {
		const response = await fetch(generateUrl('/apps/recruiting/i/{token}/confirm', { token }), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ slotId: Number(selected.value) }),
		})
		const body = await response.json()
		if (!response.ok) {
			render(body.message || t('recruiting', 'Something went wrong — please try again.'))
			return
		}
		data = body
		render()
	} catch {
		button.disabled = false
		render(t('recruiting', 'Something went wrong — please try again.'))
	}
}

render()
