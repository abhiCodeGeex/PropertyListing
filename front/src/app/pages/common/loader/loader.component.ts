import { Component, Input, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { LoaderService } from '../../../services/loder.service';

@Component({
  selector: 'app-loader',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './loader.component.html',
  styleUrls: ['./loader.component.scss']
})
export class LoaderComponent {
  @Input() loading: boolean = false;

  private readonly globalLoader = inject(LoaderService);
  protected readonly shouldRender = computed(() => this.loading && !this.globalLoader.loading());
}
