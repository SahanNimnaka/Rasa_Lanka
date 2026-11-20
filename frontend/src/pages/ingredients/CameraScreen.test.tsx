import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { CameraScreen } from './CameraScreen'

function stubMediaDevices(mediaDevices: Partial<MediaDevices>) {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: mediaDevices,
  })
}

function makeFakeStream(deviceId = 'camera-1', torch = false) {
  const stop = vi.fn()
  const applyConstraints = vi.fn().mockResolvedValue(undefined)
  const track = {
    applyConstraints,
    getCapabilities: () => ({ torch }),
    getSettings: () => ({ deviceId }),
    stop,
  } as unknown as MediaStreamTrack

  const stream = {
    getTracks: () => [track],
    getVideoTracks: () => [track],
  } as unknown as MediaStream

  return { applyConstraints, stream, stop, torch, track }
}

function renderCamera() {
  return render(
    <MemoryRouter>
      <CameraScreen />
    </MemoryRouter>,
  )
}

describe('CameraScreen', () => {
  beforeEach(() => {
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined)
  })

  afterEach(() => {
    Reflect.deleteProperty(navigator, 'mediaDevices')
  })

  it('shows a clear error when camera permission is denied', async () => {
    const getUserMedia = vi
      .fn()
      .mockRejectedValue(
        Object.assign(new Error('denied'), { name: 'NotAllowedError' }),
      )
    stubMediaDevices({
      enumerateDevices: vi.fn().mockResolvedValue([]),
      getUserMedia,
    })

    renderCamera()

    expect(
      await screen.findByRole('heading', { name: 'Camera unavailable' }),
    ).toBeInTheDocument()
    expect(screen.getByText(/Camera access was denied/)).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Upload an image instead' }),
    ).toBeInTheDocument()
  })

  it('explains when the browser does not support camera access', async () => {
    renderCamera()

    expect(
      await screen.findByRole('heading', { name: 'Camera unavailable' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/does not support camera access/),
    ).toBeInTheDocument()
  })

  it('stops camera tracks when leaving the screen', async () => {
    const fake = makeFakeStream()
    stubMediaDevices({
      enumerateDevices: vi
        .fn()
        .mockResolvedValue([
          { deviceId: 'camera-1', kind: 'videoinput', label: 'Camera' },
        ]),
      getUserMedia: vi.fn().mockResolvedValue(fake.stream),
    })

    const { unmount } = renderCamera()

    expect(await screen.findByTestId('camera-preview')).toBeInTheDocument()
    unmount()

    await waitFor(() => expect(fake.stop).toHaveBeenCalled())
  })

  it('only enables flash when the camera exposes torch control', async () => {
    const fake = makeFakeStream('camera-1', true)
    stubMediaDevices({
      enumerateDevices: vi.fn().mockResolvedValue([]),
      getUserMedia: vi.fn().mockResolvedValue(fake.stream),
    })

    renderCamera()
    await screen.findByTestId('camera-preview')

    const flashButton = screen.getByRole('button', { name: 'Turn flash on' })
    expect(flashButton).toBeEnabled()

    const user = userEvent.setup()
    await user.click(flashButton)

    expect(fake.applyConstraints).toHaveBeenCalledWith({
      advanced: [{ torch: true }],
    })
  })

  it('keeps flash disabled when torch control is unavailable', async () => {
    const fake = makeFakeStream('camera-1', false)
    stubMediaDevices({
      enumerateDevices: vi.fn().mockResolvedValue([]),
      getUserMedia: vi.fn().mockResolvedValue(fake.stream),
    })

    renderCamera()
    await screen.findByTestId('camera-preview')

    expect(screen.getByRole('button', { name: 'Turn flash on' })).toBeDisabled()
  })
})
