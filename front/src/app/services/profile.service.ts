// src/app/services/profile.service.ts
import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

@Injectable({
    providedIn: 'root',
})
export class ProfileService {
    private profileSubject = new BehaviorSubject<any>(
        JSON.parse(localStorage.getItem('profile') || 'null')
    );

    profile$ = this.profileSubject.asObservable();

    get profile() {
        return this.profileSubject.value;
    }

    setProfile(profile: any) {
        if (profile) {
            localStorage.setItem('profile', JSON.stringify(profile));
        } else {
            localStorage.removeItem('profile');
        }
        this.profileSubject.next(profile);
    }

    isProfileCompleted(): boolean {
        const p = this.profile;
        if (!p || typeof p !== 'object') {
            return false;
        }

        const requiredFields = [
            'first_name',
            'last_name',
            'dob',
            'current_address',
            'native_address',
            'aadhar',
            'marital_status',
            'gender',
            'phone',
        ];

        return requiredFields.every((field) => {
            const value = p[field];
            return value !== null && value !== undefined && String(value).trim() !== '';
        });
    }

}
