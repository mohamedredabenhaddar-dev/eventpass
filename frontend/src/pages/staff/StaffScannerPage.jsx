import { useCallback, useEffect, useRef, useState } from 'react'
import { Scanner } from '@yudiel/react-qr-scanner'
import { AlertTriangle, ArrowLeft, Camera, CameraOff, CheckCircle2, LoaderCircle, ScanLine } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { fetchAllStaffAssignedEvents, fetchStaffAccessReport, scanStaffTicket } from '../../services/ticketingService.js'
import { StaffError, StaffLoading, StaffPageHeading } from './StaffComponents.jsx'
import { eventStatusClass, formatEventDate, staffErrorMessage } from './staffUtils.js'

function scanErrorMessage(error) {
  const status = error.response?.status
  if (status === 401) return 'Your session expired. Sign in again before scanning.'
  if (status === 403) return 'You are not assigned to this event. No scan was recorded.'
  if (status === 404) return 'This QR credential is invalid or no longer available.'
  if (status === 409) return error.response?.data?.message || 'The ticket validity or access state prevents this scan.'
  if (status === 422) {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'The scan details were not valid.'
  }
  return error.response?.data?.message || 'The scan could not be completed. Please try again.'
}

function cameraErrorMessage(error) {
  if (error?.kind === 'permission-denied' || error?.kind === 'security') return 'Camera access was denied. Allow camera access in your browser settings, or enter the ticket token manually.'
  if (error?.kind === 'no-camera') return 'No camera was found. You can enter the ticket token manually.'
  if (error?.kind === 'in-use') return 'The camera is already in use by another app. Close it or enter the ticket token manually.'
  if (error?.kind === 'insecure-context') return 'Camera scanning requires HTTPS or localhost. Use the manual token field here.'
  return error?.message || 'The camera could not start. You can enter the ticket token manually.'
}

function StaffScannerPage() {
  const { eventId } = useParams()
  const scannerRef = useRef(null)
  const inFlightRef = useRef(false)
  const [eventItem, setEventItem] = useState(null)
  const [pageLoading, setPageLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [action, setAction] = useState('')
  const [gate, setGate] = useState('')
  const [scannerActive, setScannerActive] = useState(false)
  const [cameraError, setCameraError] = useState('')
  const [manualToken, setManualToken] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [scanResult, setScanResult] = useState(null)
  const [scanError, setScanError] = useState('')
  const [report, setReport] = useState(null)

  const stopCamera = useCallback(() => {
    setScannerActive(false)
    const stream = scannerRef.current?.getStream()
    stream?.getTracks().forEach((track) => track.stop())
  }, [])

  useEffect(() => {
    let active = true
    const load = async () => {
      setPageLoading(true)
      setPageError('')
      try {
        const events = await fetchAllStaffAssignedEvents()
        const found = events.find((item) => String(item.event?.id) === String(eventId)) || null
        if (active) {
          setEventItem(found)
          if (!found) setPageError('This event is not in your assigned events.')
        }
      } catch (error) {
        if (active) setPageError(staffErrorMessage(error))
      } finally { if (active) setPageLoading(false) }
    }
    load()
    return () => { active = false }
  }, [eventId])

  useEffect(() => () => {
    const stream = scannerRef.current?.getStream()
    stream?.getTracks().forEach((track) => track.stop())
  }, [])

  const submitToken = async (token) => {
    if (!token || !action || inFlightRef.current) return
    inFlightRef.current = true
    stopCamera()
    setSubmitting(true)
    setScanError('')
    setScanResult(null)
    setManualToken('')
    try {
      const result = await scanStaffTicket({ qr_token: token, action, gate })
      setScanResult(result)
      try { setReport(await fetchStaffAccessReport(eventId, 1)) } catch { /* Scan succeeded; report can be refreshed separately. */ }
    } catch (error) {
      setScanError(scanErrorMessage(error))
    } finally {
      setSubmitting(false)
      inFlightRef.current = false
    }
  }

  const handleDetected = (codes) => {
    const token = codes.find((code) => code.rawValue)?.rawValue
    if (token && !inFlightRef.current) submitToken(token)
  }

  if (pageLoading) return <StaffLoading label="Loading assigned event…" />
  if (pageError || !eventItem?.event) return <section className="staff-page"><StaffError message={pageError || 'This event is not assigned to you.'} /><Link className="staff-back-link" to="/staff/events"><ArrowLeft size={16} /> Assigned events</Link></section>
  const event = eventItem.event

  return <section className="staff-page">
    <Link className="staff-back-link" to={`/staff/events/${event.id}`}><ArrowLeft size={16} /> Event workspace</Link>
    <StaffPageHeading eyebrow="TICKET ACCESS" title="QR scanner" description="Choose the access action before starting the camera. The server validates every ticket and scan." />
    <article className="staff-scanner-event"><div><span className={eventStatusClass(event.status)}>{event.status}</span><h2>{event.title}</h2><p>{formatEventDate(event.start_at)} · {[event.venue_name, event.city].filter(Boolean).join(', ')}</p></div><Link to={`/staff/events/${event.id}/access`}>View access report</Link></article>

    <div className="staff-scanner-grid">
      <div className="staff-panel staff-scanner-panel">
        <span className="staff-eyebrow">1 · SELECT ACTION</span>
        <div className="staff-action-options" role="group" aria-label="Select scan action">
          <button type="button" className={`staff-action-choice entry${action === 'ENTRY' ? ' is-selected' : ''}`} aria-pressed={action === 'ENTRY'} onClick={() => setAction('ENTRY')}><span>ENTRY</span><small>Record a guest entering</small></button>
          <button type="button" className={`staff-action-choice exit${action === 'EXIT' ? ' is-selected' : ''}`} aria-pressed={action === 'EXIT'} onClick={() => setAction('EXIT')}><span>EXIT</span><small>Record a guest leaving</small></button>
        </div>
        <label className="staff-form-field">Gate name <span>Optional</span><input value={gate} onChange={(eventChange) => setGate(eventChange.target.value)} maxLength={120} placeholder="For example, North Gate" /></label>
        <span className="staff-eyebrow staff-camera-eyebrow">2 · CAMERA SCANNER</span>
        <div className="staff-camera-frame">
          {scannerActive ? <Scanner ref={scannerRef} onScan={handleDetected} onError={(error) => { setCameraError(cameraErrorMessage(error)); stopCamera() }} constraints={{ facingMode: 'environment' }} formats={['qr_code']} paused={submitting} classNames={{ container: 'staff-camera-view', video: 'staff-camera-video' }} /> : <div className="staff-camera-placeholder"><Camera size={38} /><strong>{cameraError ? 'Camera unavailable' : 'Camera is off'}</strong><span>{cameraError || 'Start the scanner when you are ready to scan a ticket.'}</span></div>}
        </div>
        <div className="staff-camera-actions">
          {!scannerActive ? <button className="button button-primary" type="button" disabled={!action || submitting} onClick={() => { setCameraError(''); setScanError(''); setScanResult(null); setScannerActive(true) }}><Camera size={17} /> Start scanner</button> : <button className="button button-outline" type="button" onClick={stopCamera}><CameraOff size={17} /> Stop scanner</button>}
          {submitting && <span className="staff-submitting"><LoaderCircle size={16} className="staff-spin" /> Submitting scan…</span>}
        </div>
        {!action && <p className="staff-inline-note">Select ENTRY or EXIT before starting the camera or submitting a token.</p>}
      </div>

      <div className="staff-panel staff-manual-panel">
        <span className="staff-eyebrow">MANUAL FALLBACK</span><h2>Enter ticket token</h2><p>Use this if the camera is unavailable. Enter the credential printed as a QR code; the backend checks its validity.</p>
        <form onSubmit={(eventSubmit) => { eventSubmit.preventDefault(); submitToken(manualToken.trim()) }}>
          <label className="staff-form-field">QR token<input autoComplete="off" value={manualToken} onChange={(eventChange) => setManualToken(eventChange.target.value)} placeholder="Enter or paste the ticket token" /></label>
          <button className="button button-primary" type="submit" disabled={!action || !manualToken.trim() || submitting}><ScanLine size={17} /> Submit scan</button>
        </form>
        {cameraError && <p className="staff-camera-note"><CameraOff size={16} /> {cameraError}</p>}
        {scanError && <div className="staff-alert staff-alert-error" role="alert"><AlertTriangle size={19} />{scanError}</div>}
        {scanResult && <div className="staff-scan-success" role="status"><CheckCircle2 size={21} /><div><strong>Scan accepted</strong><dl><div><dt>Ticket</dt><dd>{scanResult.ticket_reference}</dd></div><div><dt>Action</dt><dd>{scanResult.action}</dd></div><div><dt>Access state</dt><dd>{scanResult.access_state}</dd></div><div><dt>Gate</dt><dd>{scanResult.gate || 'Not specified'}</dd></div><div><dt>Scanned at</dt><dd>{formatEventDate(scanResult.scanned_at)}</dd></div></dl><button className="button button-small button-outline" type="button" onClick={() => { setScanResult(null); setScanError('') }}>Ready for next ticket</button></div></div>}
        {report?.data?.statistics && <div className="staff-mini-report" aria-live="polite"><span>Updated after successful scan</span><strong>{report.data.statistics.attendees_inside} currently inside</strong><Link to={`/staff/events/${event.id}/access`}>Open full report</Link></div>}
      </div>
    </div>
  </section>
}

export default StaffScannerPage
