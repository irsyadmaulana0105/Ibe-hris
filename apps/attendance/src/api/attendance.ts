import client from './client'
import type { Attendance, AttendanceCalendarDay } from '@/types'

interface ClockPayload {
  selfie_base64?: string
  latitude?: number
  longitude?: number
  address?: string
  location_accuracy?: number
  location_recorded_at?: string
  device_id?: string
}

export async function getTodayAttendance(): Promise<Attendance | null> {
  const res = await client.get('/attendances/today')
  return res.data.data ?? null
}

export async function clockIn(payload: ClockPayload): Promise<Attendance> {
  const res = await client.post('/attendances/clock-in', payload)
  return res.data.data
}

export async function clockOut(payload: ClockPayload): Promise<Attendance> {
  const res = await client.post('/attendances/clock-out', payload)
  return res.data.data
}

export async function getAttendanceCalendar(month: string): Promise<AttendanceCalendarDay[]> {
  const res = await client.get('/attendances/calendar', { params: { month } })
  return res.data.data
}

export async function getAttendanceDetail(id: number): Promise<Attendance> {
  const res = await client.get(`/attendances/${id}`)
  return res.data.data
}

export interface OfficeLocation {
  id: number
  name: string
  latitude: number
  longitude: number
  radius_meters: number
}

export async function getOfficeLocations(): Promise<OfficeLocation[]> {
  const res = await client.get('/office-locations')
  return res.data.data ?? []
}
