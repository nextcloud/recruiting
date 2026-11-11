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
// The candidate's "none of these times" / "reschedule" request: which form
// is open, and whether it has been sent
let requestOpen = false
let requestSent = false

const formatter = new Intl.DateTimeFormat(undefined, {
	weekday: 'long',
	year: 'numeric',
	month: 'long',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
})
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone

/**
 *
 * @param tag
 * @param attrs
 * @param children
 */
function el(tag, attrs = {}, children = []) {
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

/**
 *
 * @param errorText
 */
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
		card.appendChild(requestSection('reschedule', errorText))
	} else if (data.status === 'done') {
		card.appendChild(el('p', { text: t('recruiting', 'This interview has taken place. Thank you for your time!') }))
	} else if (data.status === 'cancelled') {
		card.appendChild(el('p', { text: t('recruiting', 'This interview was cancelled. Please contact the recruiting team if you have questions.') }))
	} else if (data.slots.length === 0) {
		card.appendChild(el('p', { text: t('recruiting', 'All proposed times have passed. Please contact the recruiting team for new options.') }))
		card.appendChild(requestSection('decline', errorText))
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
		card.appendChild(requestSection('decline', ''))
	}
	root.appendChild(card)
}

/**
 * "None of these times work for me" (decline) or "I need to reschedule":
 * a collapsed link that opens an optional message and a send button.
 *
 * @param {string} kind 'decline' or 'reschedule'
 * @param {string} errorText error from the last attempt
 */
function requestSection(kind, errorText) {
	const section = el('div', { class: 'rp-request' })
	if (requestSent) {
		section.appendChild(el('p', {
			class: 'rp-meta',
			text: kind === 'decline'
				? t('recruiting', 'Thank you — we have let the team know. They will get back to you with new options.')
				: t('recruiting', 'Thank you — we have let the team know. They will get back to you about a new time.'),
		}))
		return section
	}
	if (!requestOpen) {
		section.appendChild(el('button', {
			class: 'rp-request-toggle',
			text: kind === 'decline'
				? t('recruiting', 'None of these times work for me')
				: t('recruiting', 'I need to reschedule'),
			onclick: () => {
				requestOpen = true
				render()
			},
		}))
		return section
	}
	const textarea = el('textarea', {
		class: 'rp-request-message',
		rows: '3',
		maxlength: '2000',
		placeholder: kind === 'decline'
			? t('recruiting', 'Optional: when would suit you better?')
			: t('recruiting', 'Optional: what would work for you instead?'),
	})
	section.appendChild(el('label', { class: 'rp-meta', text: t('recruiting', 'Message to the team') }, [textarea]))
	if (errorText) {
		section.appendChild(el('p', { class: 'rp-error', text: errorText }))
	}
	section.appendChild(el('button', {
		class: 'rp-request-send',
		text: t('recruiting', 'Send'),
		onclick: (event) => sendRequest(event, kind, textarea.value),
	}))
	return section
}

/**
 *
 * @param {Event} event click event
 * @param {string} kind 'decline' or 'reschedule'
 * @param {string} message optional message
 */
async function sendRequest(event, kind, message) {
	event.target.disabled = true
	try {
		const response = await fetch(generateUrl('/apps/recruiting/i/{token}/' + kind, { token }), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ message }),
		})
		const body = await response.json()
		if (!response.ok) {
			render(body.message || t('recruiting', 'Something went wrong — please try again.'))
			return
		}
		requestSent = true
		render()
	} catch {
		render(t('recruiting', 'Something went wrong — please try again.'))
	}
}

/**
 *
 * @param event
 */
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
			// A time that was just taken drops out of the list
			if (body.interview) {
				data = body.interview
			}
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
