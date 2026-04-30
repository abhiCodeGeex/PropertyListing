// src/app/components/full-loader/full-loader.component.ts
import { Component } from '@angular/core';
import { LoaderService } from '../../../services/loder.service';

@Component({
  selector: 'app-full-loader',
  standalone: true,
  templateUrl: './full-loader.component.html',
  styleUrls: ['./full-loader.component.scss'],
})
export class FullLoaderComponent {

  constructor(public loader: LoaderService) {}
}
